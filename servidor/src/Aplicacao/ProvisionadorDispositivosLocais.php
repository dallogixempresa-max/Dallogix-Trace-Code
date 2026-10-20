<?php
declare(strict_types=1);

namespace App\Aplicacao;

use PDO;
use RuntimeException;

/**
 * Mantém as credenciais dos gateways locais alinhadas com o .env da instalação.
 * A Master não recebe nem armazena o token em texto puro; ele só é usado pelo
 * PHP local para gravar o hash indexável que a API consulta por requisição.
 */
final class ProvisionadorDispositivosLocais
{
    public static function garantir(PDO $connection, int $companyId, int $equipmentId, string $equipmentCode): void
    {
        if (strtolower(trim((string) (getenv("TRACE_INSTALLATION_MODE") ?: ""))) !== "local") {
            return;
        }

        $equipmentCode = trim($equipmentCode);
        if ($companyId < 1 || $equipmentId < 1 || !preg_match('/^[A-Za-z0-9._-]{1,70}$/', $equipmentCode)) {
            throw new RuntimeException("Não foi possível provisionar os dispositivos da Dala local.");
        }

        $devices = [
            ["CLP", "PLC-{$equipmentCode}", trim((string) (getenv("TRACE_DEVICE_TOKEN") ?: ""))],
            ["CAMERA", "CAM-{$equipmentCode}", trim((string) (getenv("CAMERA_DEVICE_TOKEN") ?: ""))],
        ];
        $statement = $connection->prepare(
            "INSERT INTO dispositivos
                (company_id, equipment_id, device_code, device_type, token_hash,
                 token_lookup_hash, token_id, token_created_at, token_last_used_at,
                 token_expires_at, token_revoked_at, active)
             VALUES (:company_id, :equipment_id, :device_code, :device_type,
                     :token_hash, :token_lookup_hash, :token_id, NOW(), NULL,
                     NULL, NULL, 1)
             ON DUPLICATE KEY UPDATE
                 company_id = VALUES(company_id), equipment_id = VALUES(equipment_id),
                 device_code = VALUES(device_code), device_type = VALUES(device_type),
                 token_hash = VALUES(token_hash), token_lookup_hash = VALUES(token_lookup_hash),
                 token_id = VALUES(token_id), token_created_at = NOW(),
                 token_last_used_at = NULL, token_expires_at = NULL,
                 token_revoked_at = NULL, active = 1",
        );

        foreach ($devices as [$deviceType, $deviceCode, $token]) {
            if (!preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
                throw new RuntimeException("Credencial {$deviceType} da instalação local está ausente ou inválida.");
            }
            $statement->execute([
                "company_id" => $companyId,
                "equipment_id" => $equipmentId,
                "device_code" => $deviceCode,
                "device_type" => $deviceType,
                "token_hash" => password_hash($token, PASSWORD_DEFAULT),
                "token_lookup_hash" => hash("sha256", $token),
                "token_id" => self::novoTokenId(),
            ]);
        }
    }

    private static function novoTokenId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            "%s-%s-%s-%s-%s",
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
