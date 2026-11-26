<?php
declare(strict_types=1);

require_once __DIR__ . "/temporizacao_leitor.php";

/**
 * Regra que liga o resultado do leitor (código ou ERROR) à passagem do M2052.
 *
 * - "sequencial" (padrão): ligação pela ORDEM, sem tempo. O resultado vai na
 *   hora para a passagem mais antiga do carregamento que ainda não tem
 *   resultado, pela hora em que o sensor viu cada saca. Não há distância,
 *   velocidade, tolerância nem janela: o leitor é disparado pelo sensor e
 *   responde a cada disparo com um resultado, que a tela envia um por vez, então
 *   o 1º resultado é da 1ª saca em aberto, o 2º da 2ª, e assim por diante.
 *   A única condição é de causa: a saca precisa ter passado pelo sensor antes
 *   do resultado chegar (passagem ainda não registrada não recebe código; a tela
 *   espera ela chegar). Uma passagem que nunca recebe resultado é fechada como
 *   SEM_LEITURA pelo prazo de segurança de sensor_eventos.php (FINALIZE_EXPIRED).
 * - "janela": regra até a v1.0.284. Liga pela janela de horário e recusa como
 *   ambígua a leitura que cabe em duas passagens.
 *
 * Requisito da fila: um resultado do leitor por saca (código ou ERROR). Um
 * resultado que se perde, ou uma passagem a mais, desloca os códigos seguintes
 * de uma saca até a sequência ter uma pausa maior que o prazo de segurança;
 * a contagem por produto não muda, porque cada código continua registrado.
 */
function trace_scanner_link_mode(): string
{
    $raw = strtolower(trim((string) (getenv("TRACE_SCANNER_LINK_MODE") ?: "")));
    return $raw === "janela" ? "janela" : "sequencial";
}

/**
 * Mesmo contrato de trace_pending_scanner_event_match(): candidates, best e
 * ambiguous, mais "link_rule" (SEQUENCIAL ou JANELA) para auditoria.
 *
 * @return array{candidates:list<array>,best:?array,ambiguous:bool,link_rule:string}
 */
function trace_scanner_event_match(
    PDO $pdo,
    int $loadingId,
    int $equipmentId,
    int $scanObservedAtMs,
    bool $forUpdate = false,
    bool $includeFinalizedNoRead = false,
    ?int $readTimeMs = null,
): array {
    if (trace_scanner_link_mode() === "janela") {
        $match = trace_pending_scanner_event_match(
            $pdo,
            $loadingId,
            $equipmentId,
            $scanObservedAtMs,
            $forUpdate,
            $includeFinalizedNoRead,
        );
        $match["link_rule"] = "JANELA";
        return $match;
    }

    $queue = trace_sequential_open_passages($pdo, $loadingId, $equipmentId, $scanObservedAtMs, $forUpdate);
    $calibration = $readTimeMs !== null ? trace_trigger_offset_calibration($pdo, $loadingId) : null;
    if ($readTimeMs !== null && $calibration !== null) {
        // Ligação pelo disparo: a hora do disparo é a captura menos o tempo de
        // leitura informado pelo Keyence. A passagem certa é a cujo pulso M2052
        // tem o mesmo afastamento já medido neste carregamento. Não depende da
        // ordem: se o resultado de B se perdeu, o código de C vai para C.
        $best = null;
        $bestError = null;
        foreach ($queue as $passage) {
            $offsetMs = $scanObservedAtMs - $readTimeMs - $passage["pulse_start_epoch_ms"];
            $error = abs($offsetMs - $calibration);
            if ($bestError === null || $error < $bestError) {
                $best = $passage + ["trigger_offset_ms" => $offsetMs];
                $bestError = $error;
            }
        }
        $matched = $best !== null && $bestError <= trace_trigger_tolerance_ms();
        return [
            "candidates" => $queue,
            // Sem passagem com esse disparo: ela ainda não chegou ao Trace; a
            // tela espera. Nunca liga a uma passagem de outro disparo.
            "best" => $matched ? $best : null,
            "ambiguous" => false,
            "link_rule" => "DISPARO",
        ];
    }

    $best = $queue[0] ?? null;
    if ($best !== null && $readTimeMs !== null) {
        $best["trigger_offset_ms"] = $scanObservedAtMs - $readTimeMs - $best["pulse_start_epoch_ms"];
    }
    return [
        "candidates" => $queue,
        "best" => $best,
        "ambiguous" => false,
        "link_rule" => "SEQUENCIAL",
    ];
}

/** Tolerância entre o afastamento medido e o calibrado (metade do menor intervalo realista entre sacas). */
function trace_trigger_tolerance_ms(): int
{
    $raw = filter_var(getenv("TRACE_SCANNER_TRIGGER_TOLERANCE_MS") ?: 300, FILTER_VALIDATE_INT);
    return max(50, min(1000, $raw === false ? 300 : (int) $raw));
}

/** Quantos vínculos recentes do carregamento formam a calibração. */
const TRACE_TRIGGER_CALIBRATION_SAMPLES = 5;

/**
 * Mediana do afastamento disparo→pulso dos últimos vínculos deste carregamento
 * (captura − tempo de leitura − início do pulso M2052). É constante na linha:
 * soma o atraso do CLP até o IN1 do leitor e o do Wedge até a tela. Sem
 * vínculos ainda (início do carregamento), devolve null e vale a ordem.
 */
function trace_trigger_offset_calibration(PDO $pdo, int $loadingId): ?int
{
    try {
        $statement = $pdo->prepare(
            "SELECT offset_ms FROM vinculos_disparo_leitor
             WHERE carregamento_id = :loading_id
             ORDER BY id DESC LIMIT " . TRACE_TRIGGER_CALIBRATION_SAMPLES,
        );
        $statement->execute(["loading_id" => $loadingId]);
        $offsets = array_map("intval", $statement->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException) {
        return null;
    }
    // Só calibra com pelo menos 3 vínculos; a mediana absorve até 2 errados.
    if (count($offsets) < 3) {
        return null;
    }
    sort($offsets);
    return $offsets[intdiv(count($offsets), 2)];
}

/** Guarda o afastamento do vínculo feito, para calibrar os próximos do carregamento. */
function trace_record_trigger_link(PDO $pdo, int $loadingId, int $sensorEventId, int $offsetMs): void
{
    if (abs($offsetMs) > 10000) {
        return;
    }
    // A calibração nunca pode impedir o registro da leitura (ex.: migração 083
    // ainda não aplicada): sem ela, o próximo resultado só volta à ordem.
    try {
        $pdo->prepare(
            "INSERT INTO vinculos_disparo_leitor (carregamento_id, sensor_event_id, offset_ms)
             VALUES (:loading_id, :sensor_event_id, :offset_ms)",
        )->execute([
            "loading_id" => $loadingId,
            "sensor_event_id" => $sensorEventId,
            "offset_ms" => $offsetMs,
        ]);
    } catch (PDOException) {
        return;
    }
}

/** Tempo de leitura (ms do disparo até a leitura) anexado pelo Keyence, ou null. */
function trace_scanner_read_time_ms(mixed $value): ?int
{
    if ($value === null || $value === "") {
        return null;
    }
    // O Keyence manda com zeros à esquerda ("0420"), que FILTER_VALIDATE_INT recusa.
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,5}$/', $value) === 1) {
        $parsed = (int) $value;
    } else {
        return null;
    }
    return $parsed < 0 || $parsed > 10000 ? null : $parsed;
}

/**
 * Separa o tempo de leitura que o Keyence anexa ao resultado
 * ("7898250782479,0420" ou "ERROR,1000"). Sem sufixo, devolve o texto como veio.
 *
 * @return array{0:string,1:?int}
 */
function trace_split_scanner_read_time(string $raw): array
{
    if (preg_match('/^(.+?)[,;](\d{1,5})$/', trim($raw), $parts) === 1) {
        $readTime = trace_scanner_read_time_ms($parts[2]);
        if ($readTime !== null) {
            return [trim($parts[1]), $readTime];
        }
    }
    return [$raw, null];
}

/** Quantas passagens em aberto a fila examina (o prazo de segurança fecha as antigas). */
const TRACE_SEQUENTIAL_QUEUE_LIMIT = 500;

/**
 * Passagens do carregamento ainda sem resultado, já vistas pelo sensor antes
 * do resultado, da mais antiga para a mais nova.
 *
 * - Só entram passagens sem nenhuma leitura: uma passagem já fechada como
 *   SEM_LEITURA não recebe o resultado da saca seguinte (vale também para o ERROR).
 * - Ordem pela hora em que o sensor viu a saca (device_detected_at, ou a hora
 *   do servidor quando o gateway não informou), com desempate pelo id: um
 *   reenvio do Node-RED pode inserir uma passagem depois da seguinte.
 * - A hora vem de dois relógios (device_detected_at no fuso do PHP e
 *   detected_at em UTC no MySQL), então a ordenação é feita aqui em epoch ms.
 *
 * @return list<array{sensor_event_id:int,detected_at_epoch_ms:int}>
 */
function trace_sequential_open_passages(
    PDO $pdo,
    int $loadingId,
    int $equipmentId,
    int $scanObservedAtMs,
    bool $forUpdate = false,
): array {
    $lockSql = $forUpdate ? " FOR UPDATE" : "";
    $limit = TRACE_SEQUENTIAL_QUEUE_LIMIT;
    // A condição de causa entra no WHERE, antes do LIMIT: passagens posteriores
    // ao resultado não podem ocupar o limite e esconder uma passagem anterior
    // que o Node-RED entregou com atraso (id maior). O pulso Modbus começa até
    // TRACE_MODBUS_PASSAGE_WINDOW_MAX_MS antes da detecção; a conferência exata
    // continua abaixo.
    $statement = $pdo->prepare(
        "SELECT s.id, s.device_detected_at, s.modbus_passage_from_epoch_ms,
                CAST(ROUND(UNIX_TIMESTAMP(s.detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
         FROM eventos_sensor s
         WHERE s.carregamento_id = :loading_id
           AND s.equipment_id = :equipment_id
           AND (
             (s.device_detected_at IS NOT NULL AND s.device_detected_at <= :device_scan_limit)
             OR (s.device_detected_at IS NULL AND s.detected_at <= FROM_UNIXTIME(:server_scan_ms / 1000.0))
           )
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
         ORDER BY s.id ASC
         LIMIT {$limit}{$lockSql}",
    );
    $statement->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $equipmentId,
        "device_scan_limit" => trace_epoch_ms_to_sql_datetime($scanObservedAtMs + TRACE_MODBUS_PASSAGE_WINDOW_MAX_MS),
        "server_scan_ms" => $scanObservedAtMs,
    ]);

    $open = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $detectedAtMs = $row["device_detected_at"] !== null
            ? trace_sql_datetime_to_epoch_ms((string) $row["device_detected_at"])
            : (int) $row["server_detected_at_epoch_ms"];
        if ($detectedAtMs < 1) {
            continue;
        }
        // Causa, não tempo: o início do pulso Modbus (ou a detecção) precisa
        // ser anterior ao resultado. Sem tolerância, sem janela.
        $fromMs = $row["device_detected_at"] !== null
            ? trace_modbus_passage_from_ms($row["modbus_passage_from_epoch_ms"], $detectedAtMs)
            : null;
        if (($fromMs ?? $detectedAtMs) > $scanObservedAtMs) {
            continue;
        }
        // Passagem que já venceu o prazo de segurança (o mesmo do
        // FINALIZE_EXPIRED) não recebe código só porque a varredura ainda não
        // rodou (heartbeat falhou, emergência, reenvio antigo).
        if ($detectedAtMs <= $scanObservedAtMs - (trace_scanner_timing()["no_read_deadline_ms"] + 3000)) {
            continue;
        }
        $open[] = [
            "sensor_event_id" => (int) $row["id"],
            "detected_at_epoch_ms" => $detectedAtMs,
            "pulse_start_epoch_ms" => $fromMs ?? $detectedAtMs,
        ];
    }
    usort(
        $open,
        static fn (array $left, array $right): int => [$left["detected_at_epoch_ms"], $left["sensor_event_id"]]
            <=> [$right["detected_at_epoch_ms"], $right["sensor_event_id"]],
    );
    return $open;
}
