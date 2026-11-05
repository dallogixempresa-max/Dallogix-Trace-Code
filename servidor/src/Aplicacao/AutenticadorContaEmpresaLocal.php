<?php

declare(strict_types=1);

namespace App\Aplicacao;

use PDO;
use Throwable;

/** Valida no Central e provisiona localmente contas de empresa usadas no PC. */
final class AutenticadorContaEmpresaLocal
{
    /** @return array{status:string,user?:array<string,mixed>,company_id?:int,remote_user_id?:int,http_status?:int,error?:string,error_code?:string} */
    public function autenticar(PDO $connection, string $email, string $password): array
    {
        $installation = $this->installation($connection);
        if (!$installation) {
            return ["status" => "unavailable"];
        }

        $centralUrl = $this->centralUrl($connection, (int) $installation["company_id"]);
        if ($centralUrl === "") {
            return ["status" => "unavailable"];
        }

        $handle = \curl_init_url_remota_segura(rtrim($centralUrl, "/") . "/api/login.php");
        if ($handle === false) {
            return ["status" => "unavailable"];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(
                ["email" => $email, "password" => $password],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Accept: application/json",
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $curlError = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($raw === false || $curlError !== 0) {
            error_log("Autenticação central do PC indisponível (cURL {$curlError}).");
            return ["status" => "unavailable"];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $status >= 500
                ? ["status" => "unavailable"]
                : ["status" => "rejected", "http_status" => 502];
        }
        if ($status >= 500) {
            return ["status" => "unavailable"];
        }
        if ($status < 200 || $status >= 300 || ($decoded["authenticated"] ?? false) !== true) {
            return [
                "status" => "rejected",
                "http_status" => $status >= 400 && $status < 500 ? $status : 401,
                "error" => trim((string) ($decoded["error"] ?? "")),
                "error_code" => trim((string) ($decoded["error_code"] ?? "")),
            ];
        }

        $remoteUser = $decoded["user"] ?? null;
        if (!is_array($remoteUser)) {
            return ["status" => "rejected", "http_status" => 401];
        }
        $remoteUserId = filter_var($remoteUser["id"] ?? null, FILTER_VALIDATE_INT);
        $remoteCompanyId = filter_var($remoteUser["company_id"] ?? null, FILTER_VALIDATE_INT);
        $remoteRole = strtoupper(trim((string) ($remoteUser["role"] ?? "")));
        $remoteEmail = strtolower(trim((string) ($remoteUser["email"] ?? "")));
        $allowedRoles = ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"];
        if (
            $remoteUserId === false || $remoteUserId < 1 ||
            $remoteCompanyId === false || (int) $remoteCompanyId !== (int) $installation["remote_company_id"] ||
            !in_array($remoteRole, $allowedRoles, true) ||
            $remoteEmail !== $email
        ) {
            return ["status" => "rejected", "http_status" => 401];
        }

        return [
            "status" => "authenticated",
            "user" => $remoteUser,
            "company_id" => (int) $installation["company_id"],
            "remote_user_id" => (int) $remoteUserId,
        ];
    }

    /** @param array{status:string,user?:array<string,mixed>,company_id?:int,remote_user_id?:int} $authentication
     * @return array<string,mixed>|null
     */
    public function provisionar(PDO $connection, array $authentication, string $password): ?array
    {
        if (($authentication["status"] ?? "") !== "authenticated") {
            return null;
        }

        $remoteUser = $authentication["user"];
        $companyId = (int) $authentication["company_id"];
        $remoteUserId = (int) $authentication["remote_user_id"];
        $email = strtolower(trim((string) $remoteUser["email"]));
        $name = trim((string) ($remoteUser["name"] ?? ""));
        $role = strtoupper(trim((string) $remoteUser["role"]));
        $mustChangePassword = !empty($remoteUser["must_change_password"]) ? 1 : 0;

        if ($name === "" || mb_strlen($name) > 160 || $companyId < 1 || $remoteUserId < 1) {
            return null;
        }

        $connection->beginTransaction();
        try {
            $byEmail = $connection->prepare(
                "SELECT id, company_id, remote_user_id, name, email, password_hash, role, active,
                        must_change_password, auth_version
                 FROM usuarios WHERE email = :email LIMIT 1 FOR UPDATE",
            );
            $byEmail->execute(["email" => $email]);
            $existing = $byEmail->fetch(PDO::FETCH_ASSOC) ?: null;

            $byRemoteId = $connection->prepare(
                "SELECT id, company_id, remote_user_id, name, email, password_hash, role, active,
                        must_change_password, auth_version
                 FROM usuarios WHERE remote_user_id = :remote_user_id LIMIT 1 FOR UPDATE",
            );
            $byRemoteId->execute(["remote_user_id" => $remoteUserId]);
            $existingRemote = $byRemoteId->fetch(PDO::FETCH_ASSOC) ?: null;

            if (
                ($existing && (int) ($existing["company_id"] ?? 0) !== $companyId) ||
                ($existing && $existing["remote_user_id"] !== null && (int) $existing["remote_user_id"] !== $remoteUserId) ||
                ($existingRemote && (!$existing || (int) $existingRemote["id"] !== (int) $existing["id"]))
            ) {
                $connection->rollBack();
                return null;
            }

            $existing ??= $existingRemote;
            if (!$existing) {
                $insert = $connection->prepare(
                    "INSERT IGNORE INTO usuarios
                        (company_id, remote_user_id, name, email, password_hash, role, active, must_change_password)
                     VALUES (:company_id, :remote_user_id, :name, :email, :password_hash, :role, 1, :must_change_password)",
                );
                $insert->execute([
                    "company_id" => $companyId,
                    "remote_user_id" => $remoteUserId,
                    "name" => $name,
                    "email" => $email,
                    "password_hash" => password_hash($password, PASSWORD_DEFAULT),
                    "role" => $role,
                    "must_change_password" => $mustChangePassword,
                ]);
            } else {
                $passwordMatches = password_verify($password, (string) $existing["password_hash"]);
                $passwordHash = $passwordMatches && !password_needs_rehash(
                    (string) $existing["password_hash"],
                    PASSWORD_DEFAULT,
                )
                    ? (string) $existing["password_hash"]
                    : password_hash($password, PASSWORD_DEFAULT);
                $changed = !$passwordMatches || $existing["remote_user_id"] === null ||
                    (int) $existing["remote_user_id"] !== $remoteUserId ||
                    (string) $existing["name"] !== $name ||
                    (string) $existing["role"] !== $role ||
                    !(bool) $existing["active"] ||
                    (int) $existing["must_change_password"] !== $mustChangePassword ||
                    password_needs_rehash((string) $existing["password_hash"], PASSWORD_DEFAULT);

                if ($changed) {
                    $update = $connection->prepare(
                        "UPDATE usuarios
                         SET remote_user_id = :remote_user_id, name = :name, password_hash = :password_hash,
                             role = :role, active = 1, must_change_password = :must_change_password,
                             auth_version = auth_version + 1
                         WHERE id = :id AND company_id = :company_id",
                    );
                    $update->execute([
                        "remote_user_id" => $remoteUserId,
                        "name" => $name,
                        "password_hash" => $passwordHash,
                        "role" => $role,
                        "must_change_password" => $mustChangePassword,
                        "id" => (int) $existing["id"],
                        "company_id" => $companyId,
                    ]);
                }
            }

            $select = $connection->prepare(
                "SELECT u.id, u.company_id, u.name, u.email, u.password_hash, u.role, u.active,
                        u.must_change_password, u.auth_version, e.login_domain AS company_login_domain
                 FROM usuarios u LEFT JOIN empresas e ON e.id = u.company_id
                 WHERE u.email = :email AND u.company_id = :company_id LIMIT 1",
            );
            $select->execute(["email" => $email, "company_id" => $companyId]);
            $localUser = $select->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$localUser || !(bool) $localUser["active"] || !password_verify($password, (string) $localUser["password_hash"])) {
                $connection->rollBack();
                return null;
            }

            $connection->commit();
            return $localUser;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{company_id:int,remote_company_id:int,sync_token:string}|null */
    private function installation(PDO $connection): ?array
    {
        $row = $connection->query(
            "SELECT company_id, remote_company_id, sync_token
             FROM instalacoes_locais WHERE id = 1 LIMIT 1",
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row["company_id"] ?? 0) < 1 || (int) ($row["remote_company_id"] ?? 0) < 1) {
            return null;
        }
        $token = strtolower(trim((string) ($row["sync_token"] ?? "")));
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }
        return [
            "company_id" => (int) $row["company_id"],
            "remote_company_id" => (int) $row["remote_company_id"],
            "sync_token" => $token,
        ];
    }

    private function centralUrl(PDO $connection, int $companyId): string
    {
        $remoteUrl = trim((string) (getenv("TRACE_CENTRAL_URL") ?: ""));
        if ($remoteUrl === "") {
            $settings = $connection->prepare(
                "SELECT sync_remote_url FROM configuracoes_empresa WHERE company_id = :company_id LIMIT 1",
            );
            $settings->execute(["company_id" => $companyId]);
            $remoteUrl = trim((string) ($settings->fetchColumn() ?: ""));
        }
        if ($remoteUrl === "") {
            $remoteUrl = trim((string) (getenv("SYNC_REMOTE_URL") ?: getenv("SYNC_REMOTE_BATCH_URL") ?: ""));
        }

        $safeUrl = \url_remota_segura($remoteUrl);
        $parts = parse_url($safeUrl);
        if (!is_array($parts) || !isset($parts["scheme"], $parts["host"])) {
            return "";
        }
        $port = isset($parts["port"]) ? ":" . (int) $parts["port"] : "";
        return strtolower((string) $parts["scheme"]) . "://" . (string) $parts["host"] . $port;
    }
}
