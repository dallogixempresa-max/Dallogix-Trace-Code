<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);
$device = require_device_token();
$payload = request_json();
$equipmentId = array_key_exists("equipment_id", $payload)
    ? filter_var($payload["equipment_id"], FILTER_VALIDATE_INT)
    : (strtoupper((string) $device["device_type"]) === "CAMERA" ? $device["equipment_id"] : false);
$deviceType = strtoupper(trim((string) ($payload["device_type"] ?? (
    strtoupper((string) $device["device_type"]) === "CAMERA" ? $device["device_type"] : ""
))));
$status = strtoupper(trim((string) ($payload["status"] ?? "ONLINE")));
$sameDeviceType = $deviceType === $device["device_type"];
// O gateway autenticado do CLP também é o produtor do sinal M2052; ele pode
// atualizar somente o status SENSOR vinculado à própria Dala.
$clpReportsAttachedSensor = $device["device_type"] === "CLP" && $deviceType === "SENSOR";
// O agente autenticado do PC pode relatar apenas a presença USB do scanner da
// própria Dala; não pode atualizar estados de CLP, câmera ou outros equipamentos.
$serverReportsAttachedScanner = $device["device_type"] === "SERVER" && $deviceType === "SCANNER";
if (
    $equipmentId === false ||
    (int) $equipmentId !== $device["equipment_id"] ||
    (!$sameDeviceType && !$clpReportsAttachedSensor && !$serverReportsAttachedScanner) ||
    !in_array($status, ["ONLINE", "OFFLINE", "ERRO"], true)
) {
    json_response(
        ["error" => "O dispositivo só pode atualizar o status da própria Dala."],
        403,
    );
}

$details = isset($payload["details"])
    ? json_encode($payload["details"], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    : null;
$pdo = db();
$equipmentMetaStatement = $pdo->prepare(
    "SELECT remote_equipment_id, equipment_code
     FROM equipamentos WHERE id = :equipment_id AND company_id = :company_id LIMIT 1",
);
$equipmentMetaStatement->execute([
    "equipment_id" => $device["equipment_id"],
    "company_id" => $device["company_id"],
]);
$equipmentMeta = $equipmentMetaStatement->fetch() ?: [];
$pdo->beginTransaction();
try {
    $current = $pdo->prepare(
        "SELECT id, status FROM status_dispositivos
         WHERE equipment_id = :equipment_id AND device_type = :device_type
         LIMIT 1 FOR UPDATE",
    );
    $current->execute([
        "equipment_id" => $device["equipment_id"],
        "device_type" => $deviceType,
    ]);
    $previous = $current->fetch();

    $upsert = $pdo->prepare(
        "INSERT INTO status_dispositivos
         (device_id, equipment_id, device_type, status, last_seen_at, details)
         VALUES (:device_id, :equipment_id, :device_type, :status, NOW(), :details)
         ON DUPLICATE KEY UPDATE device_id = VALUES(device_id), status = VALUES(status),
             last_seen_at = VALUES(last_seen_at), details = VALUES(details)",
    );
    $upsert->execute([
        "device_id" => $device["id"],
        "equipment_id" => $device["equipment_id"],
        "device_type" => $deviceType,
        "status" => $status,
        "details" => $details,
    ]);

    $statusId = (int) ($previous["id"] ?? $pdo->lastInsertId());
    if (!$previous || $previous["status"] !== $status) {
        record_operational_event(
            $pdo,
            ["id" => null, "company_id" => $device["company_id"]],
            "STATUS_DISPOSITIVO_ALTERADO",
            "status_dispositivo",
            $statusId,
            [
                "equipment_id" => $device["equipment_id"],
                "device_id" => $device["id"],
                "device_type" => $deviceType,
                "previous_status" => $previous["status"] ?? null,
                "status" => $status,
                "remote_equipment_id" => $equipmentMeta["remote_equipment_id"] === null
                    ? null : (int) $equipmentMeta["remote_equipment_id"],
                "equipment_code" => $equipmentMeta["equipment_code"] ?? null,
            ],
        );

        if (in_array($status, ["OFFLINE", "ERRO"], true)) {
            $deviceLabels = [
                "CLP" => "CLP",
                "CAMERA" => "Câmera",
                "SENSOR" => "Sensor",
                "SCANNER" => "Scanner",
                "SERVER" => "Servidor",
            ];
            $reasonCode = is_array($payload["details"] ?? null)
                ? preg_replace('/[^a-zA-Z0-9_.:-]/', "", (string) ($payload["details"]["reason_code"] ?? ""))
                : "";
            $reasonLabels = [
                "camera_config_missing" => "configuração da câmera incompleta",
                "stream_unavailable" => "imagem da câmera indisponível",
                "rtsp_tcp_unreachable" => "câmera inacessível na rede RTSP",
                "capture_failed" => "a câmera não entregou o quadro solicitado",
                "sensor_modbus_unavailable" => "sensor sem resposta Modbus",
                "scanner_usb_not_detected" => "leitor USB não detectado no PC",
                "scanner_usb_check_failed" => "verificação USB do leitor falhou",
            ];
            $deviceLabel = $deviceLabels[$deviceType] ?? "Dispositivo";
            $message = $status === "ERRO"
                ? "{$deviceLabel} reportou falha de comunicação."
                : "{$deviceLabel} sem comunicação com a Dala.";
            if (isset($reasonLabels[$reasonCode])) {
                $message = $deviceLabel . ": " . $reasonLabels[$reasonCode] . ".";
            }
            $context = json_encode([
                "device_type" => $deviceType,
                "device_id" => $device["id"],
                "equipment_id" => $device["equipment_id"],
                "previous_status" => $previous["status"] ?? null,
                "status" => $status,
                "reason_code" => $reasonCode !== "" ? $reasonCode : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $errorLog = $pdo->prepare(
                "INSERT INTO logs_erros (company_id, user_id, origem, mensagem, contexto)
                 VALUES (:company_id, NULL, 'heartbeat.dispositivo', :mensagem, :contexto)",
            );
            $errorLog->execute([
                "company_id" => $device["company_id"],
                "mensagem" => $message,
                "contexto" => $context,
            ]);
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

json_response([
    "data" => [
        "equipment_id" => $device["equipment_id"],
        "device_type" => $deviceType,
        "status" => $status,
    ],
]);
