<?php
declare(strict_types=1);

require_once __DIR__ . "/temporizacao_leitor.php";

/**
 * Regra que liga a leitura do código à passagem do M2052.
 *
 * - "janela" (padrão): só liga quando exatamente uma passagem pendente cabe na
 *   janela de horário; duas ou mais candidatas são recusadas como ambíguas.
 * - "sequencial": quando duas ou mais passagens pendentes cabem na janela, a
 *   leitura vai para a MAIS ANTIGA (fila). As sacas passam em ordem pelo sensor
 *   e pelo leitor, então com sacas coladas a leitura pertence à primeira saca
 *   ainda sem código. A janela de horário continua como conferência: passagem
 *   fora dela nunca é candidata e é fechada como SEM_LEITURA no prazo normal.
 *
 * A fila só é segura se nenhuma passagem se perde e o leitor responde a toda
 * saca (código ou ERROR). Por isso o modo sequencial só age quando o sensor
 * informa que está no modo contador do CLP (signal_source = COUNTER no
 * heartbeat). Sem o contador, a regra volta a ser a janela.
 */
function trace_scanner_link_mode(): string
{
    $raw = strtolower(trim((string) (getenv("TRACE_SCANNER_LINK_MODE") ?: "")));
    return $raw === "sequencial" ? "sequencial" : "janela";
}

/** O sensor desta Dala está online e contando sacas no CLP (modo contador)? */
function trace_sensor_counter_active(PDO $pdo, int $equipmentId): bool
{
    $statement = $pdo->prepare(
        "SELECT status, details FROM status_dispositivos
         WHERE equipment_id = :equipment_id AND device_type = 'SENSOR'
         LIMIT 1",
    );
    $statement->execute(["equipment_id" => $equipmentId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || strtoupper((string) ($row["status"] ?? "")) !== "ONLINE") {
        return false;
    }
    $details = json_decode((string) ($row["details"] ?? ""), true);
    return is_array($details) && ($details["signal_source"] ?? null) === "COUNTER";
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
    if (!trace_sensor_counter_active($pdo, $equipmentId)) {
        return $match;
    }

    // Fila: a passagem pendente mais antiga, pela ordem em que entrou no Trace
    // (a fila durável do Node-RED reenvia uma passagem por vez, em ordem).
    $candidates = $match["candidates"];
    usort(
        $candidates,
        static fn (array $left, array $right): int => $left["sensor_event_id"] <=> $right["sensor_event_id"],
    );
    $match["best"] = $candidates[0];
    $match["ambiguous"] = false;
    $match["link_rule"] = "SEQUENCIAL";
    return $match;
}
