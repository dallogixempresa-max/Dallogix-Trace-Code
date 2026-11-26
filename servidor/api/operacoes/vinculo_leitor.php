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
    return [
        "candidates" => $queue,
        "best" => $queue[0] ?? null,
        "ambiguous" => false,
        "link_rule" => "SEQUENCIAL",
    ];
}

/** Quantas passagens em aberto a fila examina (o prazo de segurança fecha as antigas). */
const TRACE_SEQUENTIAL_QUEUE_LIMIT = 50;

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
    $statement = $pdo->prepare(
        "SELECT s.id, s.device_detected_at, s.modbus_passage_from_epoch_ms,
                CAST(ROUND(UNIX_TIMESTAMP(s.detected_at) * 1000) AS UNSIGNED) AS server_detected_at_epoch_ms
         FROM eventos_sensor s
         WHERE s.carregamento_id = :loading_id
           AND s.equipment_id = :equipment_id
           AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)
         ORDER BY s.id ASC
         LIMIT {$limit}{$lockSql}",
    );
    $statement->execute([
        "loading_id" => $loadingId,
        "equipment_id" => $equipmentId,
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
        $open[] = [
            "sensor_event_id" => (int) $row["id"],
            "detected_at_epoch_ms" => $detectedAtMs,
        ];
    }
    usort(
        $open,
        static fn (array $left, array $right): int => [$left["detected_at_epoch_ms"], $left["sensor_event_id"]]
            <=> [$right["detected_at_epoch_ms"], $right["sensor_event_id"]],
    );
    return $open;
}
