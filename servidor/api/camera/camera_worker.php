<?php
declare(strict_types=1);

define("TRACE_SKIP_SESSION", true);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../../scripts/image_storage_path.php";

exigir_metodo_http(["POST"]);
$device = require_device_token(["CAMERA"]);
$payload = request_json();
$action = strtoupper(trim((string) ($payload["action"] ?? "CLAIM")));
$pdo = db();

if ($action === "CLAIM") {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT r.id, r.sensor_event_id, r.carregamento_id, r.equipment_id, r.reason,
                    r.requested_at, r.evidence_pdf_path, c.company_id
             FROM solicitacoes_captura_camera r
             JOIN carregamentos c ON c.id = r.carregamento_id
             WHERE r.equipment_id = :equipment_id
               AND (
                   r.status = 'PENDENTE'
                   OR (
                       r.status = 'CAPTURANDO'
                       AND COALESCE(r.updated_at, r.created_at, r.requested_at)
                           <= NOW(3) - INTERVAL 30 SECOND
                   )
               )
             ORDER BY r.requested_at, r.id LIMIT 1 FOR UPDATE SKIP LOCKED",
        );
        $statement->execute(["equipment_id" => $device["equipment_id"]]);
        $request = $statement->fetch();
        if (!$request) {
            $pdo->commit();
            http_response_code(204);
            exit();
        }
        $update = $pdo->prepare(
            "UPDATE solicitacoes_captura_camera
             SET status = 'CAPTURANDO', claimed_by_device_id = :device_id,
                 error_message = NULL, updated_at = NOW()
             WHERE id = :id AND equipment_id = :equipment_id
               AND (
                   status = 'PENDENTE'
                   OR (
                       status = 'CAPTURANDO'
                       AND COALESCE(updated_at, created_at, requested_at)
                           <= NOW(3) - INTERVAL 30 SECOND
                   )
               )",
        );
        $update->execute([
            "id" => $request["id"],
            "equipment_id" => $device["equipment_id"],
            "device_id" => $device["id"],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException("Pedido de captura já foi reservado.");
        }
        record_operational_event(
            $pdo,
            ["id" => null, "company_id" => (int) $request["company_id"]],
            "CAMERA_CAPTURA_INICIADA",
            "camera_request",
            (int) $request["id"],
            [
                "equipment_id" => (int) $request["equipment_id"],
                "device_id" => $device["id"],
                "reason" => $request["reason"],
            ],
        );
        $pdo->commit();
        unset($request["company_id"]);
        $request["claimed_by_device_id"] = $device["id"];
        json_response(["data" => $request]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

if ($action === "DOWNLOAD") {
    $requestId = filter_var($payload["request_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$requestId || $requestId < 1) {
        json_response(["error" => "request_id inválido."], 422);
    }
    $statement = $pdo->prepare(
        "SELECT r.id, r.equipment_id, r.evidence_pdf_path, c.company_id
         FROM solicitacoes_captura_camera r
         JOIN carregamentos c ON c.id = r.carregamento_id
         WHERE r.id = :request_id AND r.equipment_id = :equipment_id
           AND c.company_id = :company_id AND r.claimed_by_device_id = :device_id
           AND r.status = 'CAPTURANDO' AND r.evidence_pdf_path IS NOT NULL
         LIMIT 1",
    );
    $statement->execute([
        "request_id" => (int) $requestId,
        "equipment_id" => $device["equipment_id"],
        "company_id" => $device["company_id"],
        "device_id" => $device["id"],
    ]);
    $capture = $statement->fetch();
    if (!$capture) {
        json_response(["error" => "PDF da captura não encontrado para esta reserva."], 404);
    }
    $pdfPath = trace_camera_evidence_pdf_path(
        __DIR__ . "/../../../armazenamento",
        (int) $capture["company_id"],
        (int) $capture["equipment_id"],
        (int) $capture["id"],
        (string) $capture["evidence_pdf_path"],
    );
    if ($pdfPath === null) {
        json_response(["error" => "PDF da captura ausente ou inválido."], 409);
    }
    header("Content-Type: application/pdf");
    header("Content-Length: " . filesize($pdfPath));
    header("Cache-Control: no-store");
    readfile($pdfPath);
    exit();
}

if ($action !== "COMPLETE") {
    json_response(["error" => "Ação inválida."], 422);
}
$requestId = filter_var($payload["request_id"] ?? null, FILTER_VALIDATE_INT);
$evidencePdfPath = trim((string) ($payload["evidence_pdf_path"] ?? ""));
if (!$requestId || $evidencePdfPath === "") {
    json_response(["error" => "request_id e evidence_pdf_path são obrigatórios."], 422);
}
if (
    strlen($evidencePdfPath) > 500 ||
    str_contains($evidencePdfPath, "..") ||
    str_starts_with($evidencePdfPath, "/") ||
    !preg_match('/^[a-zA-Z0-9._\/-]+$/', $evidencePdfPath)
) {
    json_response(["error" => "evidence_pdf_path inválido. Use um caminho relativo permitido."], 422);
}

$pdo->beginTransaction();
try {
    $request = $pdo->prepare(
        "SELECT r.id, r.carregamento_id, r.equipment_id, r.reason, r.evidence_pdf_path, c.company_id
         FROM solicitacoes_captura_camera r
         JOIN carregamentos c ON c.id = r.carregamento_id
         WHERE r.id = :id AND r.equipment_id = :equipment_id
           AND r.claimed_by_device_id = :device_id AND r.status = 'CAPTURANDO'
         LIMIT 1 FOR UPDATE",
    );
    $request->execute([
        "id" => $requestId,
        "equipment_id" => $device["equipment_id"],
        "device_id" => $device["id"],
    ]);
    $capture = $request->fetch();
    if (!$capture) {
        $pdo->rollBack();
        json_response(["error" => "Pedido de captura não está reservado por este dispositivo."], 404);
    }
    if (!is_string($capture["evidence_pdf_path"] ?? null) || $capture["evidence_pdf_path"] !== $evidencePdfPath) {
        $pdo->rollBack();
        json_response(["error" => "O PDF concluído não corresponde ao arquivo enviado por esta captura."], 409);
    }
    $requiredPrefix = "company_" . (int) $capture["company_id"] . "/equipment_" . (int) $capture["equipment_id"] . "/";
    if (!str_starts_with($evidencePdfPath, $requiredPrefix)) {
        $pdo->rollBack();
        json_response(["error" => "evidence_pdf_path deve pertencer à empresa e ao equipamento da captura."], 422);
    }
    if (trace_camera_evidence_pdf_path(
        __DIR__ . '/../../../armazenamento',
        (int) $capture['company_id'],
        (int) $capture['equipment_id'],
        (int) $requestId,
        $evidencePdfPath,
    ) === null) {
        $pdo->rollBack();
        json_response(['error' => 'PDF de evidência não enviado, inválido ou fora da pasta da captura.'], 422);
    }
    $update = $pdo->prepare(
        "UPDATE solicitacoes_captura_camera
         SET status = 'CAPTURADA', captured_at = NOW(3), evidence_pdf_path = :evidence_pdf_path, error_message = NULL
         WHERE id = :id AND claimed_by_device_id = :device_id AND status = 'CAPTURANDO'",
    );
    $update->execute(["evidence_pdf_path" => $evidencePdfPath, "id" => $requestId, "device_id" => $device["id"]]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException("Pedido de captura já foi concluído.");
    }
    $image = $pdo->prepare(
        "INSERT INTO imagens (company_id, carregamento_id, equipment_id, path, reason, captured_at)
         VALUES (:company_id, :carregamento_id, :equipment_id, :path, :reason, NOW())",
    );
    $image->execute([
        "company_id" => $capture["company_id"],
        "carregamento_id" => $capture["carregamento_id"],
        "equipment_id" => $capture["equipment_id"],
        "path" => $evidencePdfPath,
        "reason" => $capture["reason"],
    ]);
    $imageId = (int) $pdo->lastInsertId();
    record_operational_event(
        $pdo,
        ["id" => null, "company_id" => (int) $capture["company_id"]],
        "CAMERA_CAPTURA_CONCLUIDA",
        "camera_request",
        (int) $requestId,
        [
            "image_id" => $imageId,
            "carregamento_id" => (int) $capture["carregamento_id"],
            "equipment_id" => (int) $capture["equipment_id"],
            "device_id" => $device["id"],
            "evidence_pdf_path" => $evidencePdfPath,
            "reason" => $capture["reason"],
        ],
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof RuntimeException && $exception->getMessage() === "Pedido de captura já foi concluído.") {
        json_response(["error" => $exception->getMessage()], 409);
    }
    throw $exception;
}
json_response([
    "data" => [
        "request_id" => (int) $requestId,
        "image_id" => $imageId,
        "status" => "CAPTURADA",
    ],
]);
