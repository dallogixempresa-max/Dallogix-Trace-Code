<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/ValidadorEvidenciasOperacionais.php";

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$loadingId = filter_var($_GET["carregamento_id"] ?? null, FILTER_VALIDATE_INT);
if (!$loadingId) {
    json_response(["error" => "Carregamento obrigatório."], 422);
}

session_write_close();
$pdo = db();
$loading = $pdo->prepare(
    "SELECT c.id, c.equipment_id, c.state, c.stop_reason, c.started_at, c.finished_at,
            COALESCE(c.leituras_validas, 0) AS leituras_validas,
            (SELECT COUNT(*) FROM eventos_sensor s
             WHERE s.carregamento_id = c.id AND s.equipment_id = c.equipment_id
               AND s.conta_na_meta = 1) AS sacas_detectadas,
            r.number AS romaneio_number, rt.plate, e.equipment_code,
            (SELECT JSON_UNQUOTE(JSON_EXTRACT(la.metadata, '$.message'))
             FROM logs_auditoria la
             WHERE la.company_id = c.company_id
               AND la.action IN ('ALERTA_FIM_CARGA', 'ALERTA_FIM_PRODUTO')
               AND la.entity_type = 'carregamento'
               AND la.entity_id = c.id
             ORDER BY la.id DESC
             LIMIT 1) AS end_product_alert
     FROM carregamentos c
     JOIN romaneios r ON r.id = c.romaneio_id
     JOIN romaneio_caminhoes rt ON rt.id = c.truck_id
     JOIN equipamentos e ON e.id = c.equipment_id
     WHERE c.id = :id AND c.company_id = :company_id LIMIT 1",
);
$loading->execute(["id" => $loadingId, "company_id" => $user["company_id"]]);
$data = $loading->fetch();
if (!$data) {
    json_response(["error" => "Carregamento não encontrado."], 404);
}
$evidenceValidator = new \App\Aplicacao\ValidadorEvidenciasOperacionais(
    $pdo,
    realpath(__DIR__ . "/../../../armazenamento") ?: "",
);

$count = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};
$data["leituras_validas"] = (int) ($data["leituras_validas"] ?? 0);
$data["sacas_detectadas"] = (int) ($data["sacas_detectadas"] ?? 0);
$data["sacas_retornadas"] = $count(
    $pdo,
    "SELECT COALESCE(SUM(ret.quantity), 0) FROM retornos ret
     LEFT JOIN leituras lr ON lr.id = ret.leitura_id
     WHERE ret.carregamento_id = :id AND (lr.id IS NULL OR lr.conta_na_meta = 1)",
    ["id" => $loadingId],
);
$data["leituras_liquidas"] = max(0, $data["leituras_validas"] - $data["sacas_retornadas"]);
$data["leituras_scanner"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras
     WHERE carregamento_id = :id AND barcode IS NOT NULL
       AND result IN ('VALIDO', 'PRODUTO_INCORRETO', 'EXCESSO')",
    ["id" => $loadingId],
);
$data["leituras_excesso"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'EXCESSO'",
    ["id" => $loadingId],
);
$stopReason = strtoupper(trim((string) ($data["stop_reason"] ?? "")));
$data["parada_automatica_confirmada"] = false;
if (in_array($stopReason, ["COTA_ROMANEIO_ATINGIDA", "EXCESSO"], true)) {
    $stopAction = $stopReason === "EXCESSO" ? "EXCESSO_DETECTADO" : "QUANTIDADE_PLANEJADA_ATINGIDA";
    $data["parada_automatica_confirmada"] = $count(
        $pdo,
        "SELECT COUNT(*) FROM logs_auditoria a
         WHERE a.company_id = :company_id AND a.action = :action
           AND a.entity_type = 'carregamento' AND a.entity_id = :loading_id
           AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.stop_reason')) = :stop_reason
           AND (
             EXISTS (
               SELECT 1 FROM solicitacoes_comandos_clp q
               WHERE q.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                 AND q.carregamento_id = :command_loading_id AND q.company_id = :command_company_id
                 AND q.command = 'PAUSAR_CARREGAMENTO' AND q.status = 'APLICADO'
             )
             OR EXISTS (
               SELECT 1 FROM logs_auditoria ack
               WHERE ack.company_id = a.company_id
                 AND ack.action = 'COMANDO_CLP_CONCLUIDO'
                 AND ack.entity_type = 'solicitacao_comando_clp'
                 AND (
                   ack.entity_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                   OR CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.aggregate_id')) AS UNSIGNED)
                     = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_request_id')) AS UNSIGNED)
                 )
                 AND JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.command')) = 'PAUSAR_CARREGAMENTO'
                 AND JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.status')) = 'APLICADO'
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_carregamento_id')) AS UNSIGNED) = :ack_remote_loading_id
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ack.metadata, '$.payload.data.remote_equipment_id')) AS UNSIGNED) = :ack_remote_equipment_id
             )
           )",
        [
            "company_id" => $user["company_id"],
            "action" => $stopAction,
            "loading_id" => $loadingId,
            "stop_reason" => $stopReason,
            "command_loading_id" => $loadingId,
            "command_company_id" => $user["company_id"],
            "ack_remote_loading_id" => $loadingId,
            "ack_remote_equipment_id" => (int) $data["equipment_id"],
        ],
    ) > 0;
}
$data["excessos_detectados"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
     WHERE i.carregamento_id = :id AND i.reason = 'EXCESSO'",
    ["id" => $loadingId],
);
$data["excessos_com_foto"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
     WHERE i.carregamento_id = :id AND i.reason = 'EXCESSO' AND i.status = 'CAPTURADA'",
    ["id" => $loadingId],
);
$data["fotos_excesso_pendentes"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
     WHERE i.carregamento_id = :id AND i.reason = 'EXCESSO' AND i.status IN ('PENDENTE', 'CAPTURANDO')",
    ["id" => $loadingId],
);
$data["fotos_excesso_com_erro"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
     WHERE i.carregamento_id = :id AND i.reason = 'EXCESSO' AND i.status = 'ERRO'",
    ["id" => $loadingId],
);
$data["sem_leitura"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'SEM_LEITURA' AND conta_na_meta = 1",
    ["id" => $loadingId],
);
$data["sem_leitura_evidencias_obrigatorias"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'SEM_LEITURA'",
    ["id" => $loadingId],
);
$data["sem_leitura_evidencias_com_imagem"] = max(
    0,
    (int) $data["sem_leitura_evidencias_obrigatorias"]
        - $evidenceValidator->contarLeiturasSemImagemValida(
            (int) $user["company_id"],
            (int) $loadingId,
            (int) $data["equipment_id"],
            "SEM_LEITURA",
        ),
);
$data["sem_leitura_com_foto"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras l
     WHERE l.carregamento_id = :id AND l.result = 'SEM_LEITURA' AND l.conta_na_meta = 1
       AND l.sensor_event_id IS NOT NULL
       AND EXISTS (
         SELECT 1 FROM solicitacoes_imagens_evidencia e
         WHERE e.carregamento_id = l.carregamento_id
           AND e.sensor_event_id = l.sensor_event_id
           AND e.reason = 'SEM_LEITURA' AND e.status = 'CAPTURADA'
           AND EXISTS (SELECT 1 FROM eventos_sensor s WHERE s.id = e.sensor_event_id AND s.conta_na_meta = 1)
           AND EXISTS (
             SELECT 1 FROM imagens i
             WHERE i.company_id = l.company_id AND i.carregamento_id = l.carregamento_id
               AND i.reason = 'SEM_LEITURA'
               AND (i.reading_id = l.id OR (
                 i.reading_id IS NULL AND i.path LIKE CONCAT('%keyence-', e.id, '-%')
               ))
           )
       )",
    ["id" => $loadingId],
);
// Sacas sem produto: SEM_LEITURA com product_id NULL. Ocorre em romaneios com
// múltiplos produtos quando o sensor registra a passagem mas o leitor não lê
// o código — a identidade do produto é desconhecida. Exibida separadamente
// para não imputar a falha a nenhum produto específico.
$data["leituras_sem_produto"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'SEM_LEITURA' AND conta_na_meta = 1 AND product_id IS NULL",
    ["id" => $loadingId],
);
$data["produtos_incorretos"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM leituras WHERE carregamento_id = :id AND result = 'PRODUTO_INCORRETO'",
    ["id" => $loadingId],
);
$data["produtos_incorretos_com_foto"] = max(
    0,
    (int) $data["produtos_incorretos"]
        - $evidenceValidator->contarLeiturasSemImagemValida(
            (int) $user["company_id"],
            (int) $loadingId,
            (int) $data["equipment_id"],
            "PRODUTO_INCORRETO",
        ),
);
$data["ocorrencias"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM ocorrencias WHERE carregamento_id = :id",
    ["id" => $loadingId],
);
$data["imagens_pendentes"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i WHERE i.carregamento_id = :id AND i.status IN ('PENDENTE','CAPTURANDO')",
    ["id" => $loadingId],
);
$data["imagens_com_falha"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i WHERE i.carregamento_id = :id AND i.status = 'ERRO'",
    ["id" => $loadingId],
);
$data["leituras_pendentes_sensor"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM eventos_sensor s
     WHERE s.carregamento_id = :id
       AND NOT EXISTS (SELECT 1 FROM leituras l WHERE l.sensor_event_id = s.id)",
    ["id" => $loadingId],
);
$sensorOutbox = $pdo->prepare(
    "SELECT CASE
       WHEN status = 'ONLINE' AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND)
       THEN JSON_UNQUOTE(JSON_EXTRACT(details, '$.sensor_event_outbox_pending'))
       ELSE NULL
     END AS pending_events,
     CASE
       WHEN status = 'ONLINE' AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND)
       THEN JSON_EXTRACT(details, '$.sensor_event_outbox_pending_by_loading')
       ELSE NULL
     END AS pending_events_by_loading
     FROM status_dispositivos
     WHERE equipment_id = :equipment_id AND device_type = 'SENSOR'
     LIMIT 1",
);
$sensorOutbox->execute(["equipment_id" => (int) $data["equipment_id"]]);
$sensorOutboxState = $sensorOutbox->fetch(PDO::FETCH_ASSOC) ?: [];
$pendingEventsByLoading = is_string($sensorOutboxState["pending_events_by_loading"] ?? null)
    ? json_decode($sensorOutboxState["pending_events_by_loading"], true)
    : null;
$sensorOutboxPending = is_array($pendingEventsByLoading)
    ? (int) ($pendingEventsByLoading[(string) $loadingId] ?? 0)
    : ($sensorOutboxState["pending_events"] ?? null);
$data["sensor_outbox_pendentes"] = $sensorOutboxPending === false || $sensorOutboxPending === null
    ? null
    : max(0, (int) $sensorOutboxPending);
$data["imagens_incidentes"] = $count(
    $pdo,
    "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia WHERE carregamento_id = :id AND status = 'CAPTURADA' AND reason <> 'NORMAL'",
    ["id" => $loadingId],
);
json_response(["data" => $data]);
