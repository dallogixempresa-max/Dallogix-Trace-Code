<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

exigir_metodo_http(["POST"]);
$payload = ler_json_da_requisicao();
$code = strtoupper(trim((string) ($payload["activation_code"] ?? "")));
$installationToken = strtolower(trim((string) ($payload["installation_token"] ?? "")));
$hasInstallationToken = $installationToken !== "";
$normalized = str_replace("-", "", $code);
verificar_taxa_de_ativacao($code);
if (!codigo_ativacao_empresa_valido($code)) {
    responder_json([
        "error" => "Código de ativação incorreto.",
        "error_code" => "ACTIVATION_CODE_INVALID",
    ], 422);
}
if ($hasInstallationToken && !credencial_instalacao_valida($installationToken)) {
    responder_json([
        "error" => "A instalação precisa apresentar uma credencial aleatória de 256 bits.",
        "error_code" => "INSTALLATION_TOKEN_INVALID",
    ], 422);
}

$statement = obter_conexao_banco()->prepare(
    "SELECT p.id AS installation_id, p.company_id, p.equipment_id,
            c.name, c.login_domain,
            (SELECT l.status FROM licencas l WHERE l.company_id = c.id ORDER BY l.id DESC LIMIT 1) AS license_status
     FROM instalacoes_industriais p
     JOIN empresas c ON c.id = p.company_id
     WHERE p.activation_code_hash = :code_hash
       AND p.activation_code_used_at IS NULL
       AND p.activation_code_expires_at > NOW()
       AND p.archived_at IS NULL
       AND p.access_blocked_at IS NULL
       AND c.archived_at IS NULL
     LIMIT 1",
);
$statement->execute(["code_hash" => hash("sha256", $normalized)]);
$empresa = $statement->fetch();
if (!$empresa) {
    responder_json([
        "error" => "Código de ativação incorreto.",
        "error_code" => "ACTIVATION_CODE_INVALID",
    ], 401);
}
if (($empresa["license_status"] ?? "") !== "ATIVA") {
    responder_json([
        "error" => "Ative a licença da empresa antes de ativar o PC industrial.",
        "error_code" => "LICENSE_INACTIVE",
    ], 409);
}

if ($hasInstallationToken) {
    $usuarioAtivador = exigir_perfil(["ADMIN_EMPRESA"]);
    exigir_csrf();
    if ((int) ($usuarioAtivador["company_id"] ?? 0) !== (int) $empresa["company_id"]) {
        responder_json([
            "error" => "O administrador autenticado pertence a outra empresa.",
            "error_code" => "ACTIVATION_COMPANY_MISMATCH",
        ], 403);
    }
    $updateToken = obter_conexao_banco()->prepare(
        "UPDATE instalacoes_industriais
         SET sync_token_hash = :token_hash,
             activation_code = NULL,
             activation_code_hash = NULL,
             activation_code_preview = NULL,
             activation_code_used_at = NOW(),
             activated_at = NOW()
         WHERE id = :id AND activation_code_hash = :code_hash
           AND activation_code_used_at IS NULL AND activation_code_expires_at > NOW()
           AND sync_token_hash IS NULL",
    );
    $updateToken->execute([
        "token_hash" => hash("sha256", $installationToken),
        "code_hash" => hash("sha256", $normalized),
        "id" => $empresa["installation_id"],
    ]);
    if ($updateToken->rowCount() !== 1) {
        responder_json([
            "error" => "O código de ativação expirou ou já foi utilizado.",
            "error_code" => "ACTIVATION_CODE_INVALID",
        ], 409);
    }
}

responder_json([
    "data" => [
        "company_id" => (int) $empresa["company_id"],
        "name" => $empresa["name"],
        "login_domain" => $empresa["login_domain"],
        "installation_id" => (int) $empresa["installation_id"],
        "equipment_id" => $empresa["equipment_id"] === null ? null : (int) $empresa["equipment_id"],
        "active" => true,
    ],
]);
