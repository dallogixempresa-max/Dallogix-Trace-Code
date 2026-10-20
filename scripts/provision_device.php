<?php
declare(strict_types=1);

$options = getopt("", ["equipment-id:", "device-type:", "device-code:", "token:"]);
$equipmentId = filter_var($options["equipment-id"] ?? null, FILTER_VALIDATE_INT);
$deviceType = strtoupper(trim((string) ($options["device-type"] ?? "")));
$deviceCode = trim((string) ($options["device-code"] ?? ""));
$token = (string) ($options["token"] ?? "");
$allowedTypes = ["CLP", "SCANNER", "SENSOR", "CAMERA", "SERVER"];
if (
    !$equipmentId ||
    !in_array($deviceType, $allowedTypes, true) ||
    !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $deviceCode) ||
    strlen($token) < 32 ||
    strlen($token) > 512
) {
    fwrite(STDERR, "Uso: php provision_device.php --equipment-id=N --device-type=CLP --device-code=PLC-EST-001 --token=TOKEN_FORTE\n");
    exit(2);
}

$host = getenv("DB_HOST") ?: "mysql";
$name = getenv("DB_NAME") ?: "trace_local";
$user = getenv("DB_USER") ?: "trace";
$password = trim((string) getenv("DB_PASSWORD"));
if ($password === "") {
    fwrite(STDERR, "ERRO: DB_PASSWORD não configurado.\n");
    exit(2);
}
$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$equipment = $pdo->prepare("SELECT id, company_id FROM equipamentos WHERE id = :id LIMIT 1");
$equipment->execute(["id" => $equipmentId]);
$row = $equipment->fetch();
if (!$row) {
    fwrite(STDERR, "ERRO: equipamento não encontrado.\n");
    exit(3);
}

$tokenId = sprintf(
    "%s-%s-%s-%s-%s",
    bin2hex(random_bytes(4)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(6)),
);

$statement = $pdo->prepare(
    "INSERT INTO dispositivos (company_id, equipment_id, device_code, device_type, token_hash, token_lookup_hash, token_id, token_created_at, token_last_used_at, token_expires_at, token_revoked_at, active)
     VALUES (:company_id, :equipment_id, :device_code, :device_type, :token_hash, :token_lookup_hash, :token_id, NOW(), NULL, NULL, NULL, 1)
     ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), equipment_id = VALUES(equipment_id),
         device_type = VALUES(device_type), token_hash = VALUES(token_hash),
         token_lookup_hash = VALUES(token_lookup_hash), token_id = VALUES(token_id),
         token_created_at = NOW(), token_last_used_at = NULL,
         token_expires_at = NULL, token_revoked_at = NULL, active = 1",
);
$statement->execute([
    "company_id" => $row["company_id"],
    "equipment_id" => $equipmentId,
    "device_code" => $deviceCode,
    "device_type" => $deviceType,
    "token_hash" => password_hash($token, PASSWORD_DEFAULT),
    "token_lookup_hash" => hash("sha256", $token),
    "token_id" => $tokenId,
]);
echo "OK: dispositivo {$deviceCode} provisionado para o equipamento {$equipmentId}; credencial rotacionada. O token não foi armazenado em texto puro.\n";
