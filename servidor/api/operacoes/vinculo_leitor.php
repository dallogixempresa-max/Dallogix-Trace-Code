<?php
declare(strict_types=1);

require_once __DIR__ . "/temporizacao_leitor.php";

/**
 * Regra que liga a leitura do código à passagem do M2052.
 *
 * - "sequencial" (padrão): quando duas ou mais passagens pendentes cabem na
 *   janela, a leitura vai na hora para a MAIS ANTIGA ainda sem leitura (fila),
 *   sem espera. As sacas passam em ordem pelo sensor e pelo leitor, e o leitor
 *   responde a cada disparo com um resultado (código ou ERROR), que a tela envia
 *   um por vez: com sacas próximas, o código pertence à primeira saca ainda sem
 *   leitura. A janela de horário continua como conferência: passagem fora dela
 *   nunca é candidata e é fechada como SEM_LEITURA no prazo normal, o que limita
 *   a uma ou duas sacas o efeito de um resultado que se perdeu.
 * - "janela": só liga quando exatamente uma passagem pendente cabe na janela;
 *   duas ou mais candidatas são recusadas como ambíguas (regra até a v1.0.284).
 *
 * Funciona no modo pulso do M2052, sem contador no CLP.
 */
function trace_scanner_link_mode(): string
{
    $raw = strtolower(trim((string) (getenv("TRACE_SCANNER_LINK_MODE") ?: "")));
    return $raw === "janela" ? "janela" : "sequencial";
}

/**
 * Mesmo contrato de trace_pending_scanner_event_match(), com a regra
 * configurada em TRACE_SCANNER_LINK_MODE. Acrescenta "link_rule"
 * (JANELA ou SEQUENCIAL) para auditoria.
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
    $match = trace_pending_scanner_event_match(
        $pdo,
        $loadingId,
        $equipmentId,
        $scanObservedAtMs,
        $forUpdate,
        $includeFinalizedNoRead,
    );
    $match["link_rule"] = "JANELA";
    if (!$match["ambiguous"] || trace_scanner_link_mode() !== "sequencial") {
        return $match;
    }

    $queue = trace_sequential_queue($pdo, $loadingId, $match["candidates"]);
    if ($queue === null) {
        return $match;
    }
    $match["best"] = $queue[0];
    $match["ambiguous"] = false;
    $match["link_rule"] = "SEQUENCIAL";
    return $match;
}

/** Atraso máximo entre detectar a passagem e ela chegar ao servidor para a fila confiar nela. */
const TRACE_SEQUENTIAL_MAX_ARRIVAL_DELAY_MS = 1000;

/**
 * Monta a fila das candidatas ou devolve null quando a fila não é confiável
 * (aí vale a regra da janela, que recusa a leitura ambígua sem trocar códigos).
 *
 * - Só entram passagens ainda SEM leitura: com ERROR (includeFinalizedNoRead)
 *   a janela também devolve passagens já fechadas como SEM_LEITURA, que não
 *   podem receber a leitura da saca seguinte.
 * - Ordem pela hora em que o sensor viu a saca (device_detected_at), não pela
 *   ordem de inserção: um reenvio do Node-RED pode inserir uma passagem depois
 *   da seguinte.
 * - Toda candidata precisa ser precisa e pontual: ter o intervalo Modbus da
 *   detecção (passagens sem esse intervalo, como as interpoladas, ficam de
 *   fora) e ter chegado ao servidor em até TRACE_SEQUENTIAL_MAX_ARRIVAL_DELAY_MS.
 *   Uma passagem atrasada pode ter perdido o próprio código (a tela desiste de
 *   esperar a passagem 3 s depois da captura), e a fila deslocaria as sacas
 *   seguintes. Nos carregamentos reais as passagens chegam em até ~0,8 s.
 *
 * @param list<array{sensor_event_id:int}> $candidates
 * @return ?list<array>
 */
function trace_sequential_queue(PDO $pdo, int $loadingId, array $candidates): ?array
{
    $byId = [];
    foreach ($candidates as $candidate) {
        $byId[(int) $candidate["sensor_event_id"]] = $candidate;
    }
    if (count($byId) < 2) {
        return null;
    }
    $placeholders = implode(",", array_fill(0, count($byId), "?"));
    $statement = $pdo->prepare(
        "SELECT s.id, s.device_detected_at, s.modbus_passage_from_epoch_ms,
                CAST(ROUND(UNIX_TIMESTAMP(s.detected_at) * 1000) AS UNSIGNED) AS server_received_at_epoch_ms,
                (SELECT COUNT(*) FROM leituras l WHERE l.sensor_event_id = s.id) AS readings
         FROM eventos_sensor s
         WHERE s.carregamento_id = ? AND s.id IN ({$placeholders})",
    );
    $statement->execute([$loadingId, ...array_keys($byId)]);

    $pending = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row["readings"] > 0) {
            continue;
        }
        if ($row["device_detected_at"] === null || $row["modbus_passage_from_epoch_ms"] === null) {
            return null;
        }
        $detectedAtMs = trace_sql_datetime_to_epoch_ms((string) $row["device_detected_at"]);
        $arrivalDelayMs = (int) $row["server_received_at_epoch_ms"] - $detectedAtMs;
        if ($detectedAtMs < 1 || $arrivalDelayMs > TRACE_SEQUENTIAL_MAX_ARRIVAL_DELAY_MS) {
            return null;
        }
        $pending[] = ["detected_at_epoch_ms" => $detectedAtMs] + $byId[(int) $row["id"]];
    }
    if ($pending === []) {
        return null;
    }
    usort(
        $pending,
        static fn (array $left, array $right): int => [$left["detected_at_epoch_ms"], $left["sensor_event_id"]]
            <=> [$right["detected_at_epoch_ms"], $right["sensor_event_id"]],
    );
    return $pending;
}
