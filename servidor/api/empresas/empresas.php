<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/EstadoFisicoClp.php";

use App\Aplicacao\EstadoFisicoClp;

exigir_metodo_http(["GET", "POST", "PUT", "DELETE"]);

$usuarioAtor = exigir_sessao_usuario();

/**
 * Remove uma empresa e todos os dados operacionais que pertencem a ela.
 *
 * A exclusão normal continua bloqueada quando há vínculos. Esta rotina só é
 * chamada pelo fluxo explícito de "zerar empresa" do Administrador Dallogix.
 */
function remover_empresa_com_dados(PDO $pdo, int $companyId): void
{
    // Use placeholders even though the ID has already been validated. This
    // keeps the destructive reset consistent with the rest of the API.
    $companyIdSql = "?";

    // Remova primeiro as tabelas que não possuem company_id próprio, mas
    // apontam para registros operacionais da empresa.
    $queriesDependentes = [
        "DELETE FROM solicitacoes_captura_camera
         WHERE sensor_event_id IN (
             SELECT id FROM eventos_sensor
             WHERE carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
                OR equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})
         )
            OR carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            OR equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})
            OR claimed_by_device_id IN (SELECT id FROM dispositivos WHERE company_id = {$companyIdSql})",
        "DELETE FROM solicitacoes_comandos_clp
         WHERE carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            OR equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})
            OR requested_by IN (SELECT id FROM usuarios WHERE company_id = {$companyIdSql})
            OR claimed_by_device_id IN (SELECT id FROM dispositivos WHERE company_id = {$companyIdSql})",
        "DELETE FROM status_dispositivos
         WHERE equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})
            OR device_id IN (SELECT id FROM dispositivos WHERE company_id = {$companyIdSql})",
        "DELETE FROM imagens
         WHERE company_id = {$companyIdSql}
            OR carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            OR equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})
            OR reading_id IN (
                SELECT id FROM leituras
                WHERE company_id = {$companyIdSql}
                   OR carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            )",
        "DELETE FROM retornos
         WHERE carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            OR leitura_id IN (
                SELECT id FROM leituras
                WHERE company_id = {$companyIdSql}
                   OR carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            )",
        "DELETE FROM eventos_sensor
         WHERE carregamento_id IN (SELECT id FROM carregamentos WHERE company_id = {$companyIdSql})
            OR equipment_id IN (SELECT id FROM equipamentos WHERE company_id = {$companyIdSql})",
        "DELETE FROM romaneio_itens
         WHERE romaneio_id IN (SELECT id FROM romaneios WHERE company_id = {$companyIdSql})
            OR truck_id IN (
                SELECT id FROM romaneio_caminhoes
                WHERE romaneio_id IN (SELECT id FROM romaneios WHERE company_id = {$companyIdSql})
            )",
        "DELETE FROM romaneio_caminhoes
         WHERE romaneio_id IN (SELECT id FROM romaneios WHERE company_id = {$companyIdSql})",
    ];

    foreach ($queriesDependentes as $query) {
        $statement = $pdo->prepare($query);
        $statement->execute(array_fill(0, substr_count($query, "?"), $companyId));
    }

    // Todas as tabelas com company_id são descobertas no próprio schema para
    // que o reset continue cobrindo novas tabelas isoladas por empresa.
    $tables = $pdo->query(
        "SELECT DISTINCT table_name
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND column_name = 'company_id'
           AND table_name <> 'empresas'",
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        $statement = $pdo->prepare("DELETE FROM `{$table}` WHERE company_id = ?");
        $statement->execute([$companyId]);
    }

    $delete = $pdo->prepare("DELETE FROM empresas WHERE id = :id");
    $delete->execute(["id" => $companyId]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($usuarioAtor["role"] !== "ADMIN_DALLOGIX") {
        responder_json(
            ["error" => "Somente o Administrador Dallogix pode criar empresas."],
            403,
        );
    }

    exigir_csrf();
    $payload = ler_json_da_requisicao();
    if (($payload["action"] ?? "") === "generate_activation_code") {
        $companyId = filter_var($payload["company_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$companyId) {
            responder_json(["error" => "Empresa não informada."], 422);
        }
        $pdo = obter_conexao_banco();
        $pdo->beginTransaction();
        try {
            $company = $pdo->prepare(
                "SELECT id, name, login_domain, activation_code, activation_code_hash,
                        activation_code_used_at,
                        (activation_code_expires_at IS NOT NULL AND activation_code_expires_at > NOW()) AS activation_code_unexpired,
                        archived_at,
                        (SELECT l.status FROM licencas l WHERE l.company_id = empresas.id ORDER BY l.id DESC LIMIT 1) AS license_status
                 FROM empresas WHERE id = :id LIMIT 1 FOR UPDATE",
            );
            $company->execute(["id" => $companyId]);
            $empresa = $company->fetch();
            if (!$empresa) {
                $pdo->rollBack();
                responder_json(["error" => "Empresa não encontrada."], 404);
            }
            if ($empresa["archived_at"] !== null) {
                $pdo->rollBack();
                responder_json(["error" => "Não é possível gerar ativação para uma empresa arquivada."], 409);
            }
            if (($empresa["license_status"] ?? "") !== "ATIVA") {
                $pdo->rollBack();
                responder_json(["error" => "Ative a licença da empresa antes de liberar o código de ativação."], 409);
            }
            $codePending = trim((string) ($empresa["activation_code"] ?? "")) !== ""
                && empty($empresa["activation_code_used_at"])
                && (bool) ($empresa["activation_code_unexpired"] ?? false);
            if ($codePending) {
                $pdo->commit();
                responder_json([
                    "data" => [
                        "id" => (int) $empresa["id"],
                        "name" => $empresa["name"],
                        "login_domain" => $empresa["login_domain"],
                        "activation_code" => $empresa["activation_code"],
                    ],
                ]);
            }
            $activation = gerar_codigo_ativacao_empresa();
            $update = $pdo->prepare(
                "UPDATE empresas
                 SET activation_code = :code,
                     activation_code_hash = :code_hash,
                     activation_code_preview = :code_preview,
                     activation_code_created_at = NOW(),
                     activation_code_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY),
                     activation_code_used_at = NULL
                 WHERE id = :id",
            );
            $update->execute([
                "code" => $activation["code"],
                "code_hash" => $activation["hash"],
                "code_preview" => $activation["preview"],
                "id" => $empresa["id"],
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        responder_json([
            "data" => [
                "id" => (int) $empresa["id"],
                "name" => $empresa["name"],
                "login_domain" => $empresa["login_domain"],
                "activation_code" => $activation["code"],
            ],
        ], 201);
    }
    $nomeEmpresa = trim((string) ($payload["name"] ?? ""));
    if (!nome_empresa_valido($nomeEmpresa)) {
        responder_json(["error" => "Informe o nome da empresa."], 422);
    }

    $pdo = obter_conexao_banco();
    try {
        $pdo->beginTransaction();
        $empresaExistente = $pdo->prepare(
            "SELECT id FROM empresas WHERE name = :name LIMIT 1",
        );
        $empresaExistente->execute(["name" => $nomeEmpresa]);
        if ($empresaExistente->fetch()) {
            $pdo->rollBack();
            responder_json(["error" => "Já existe uma empresa com este nome."], 409);
        }

        $loginDomain = gerar_dominio_login_empresa($pdo, $nomeEmpresa);
        $insercao = $pdo->prepare(
            "INSERT INTO empresas
                (name, login_domain)
             VALUES
                (:name, :login_domain)",
        );
        $insercao->execute([
            "name" => $nomeEmpresa,
            "login_domain" => $loginDomain,
        ]);
        $createdCompanyId = (int) $pdo->lastInsertId();
        registrar_evento_operacional(
            $pdo,
            $usuarioAtor,
            "EMPRESA_CRIADA",
            "company",
            $createdCompanyId,
            [
                "name" => $nomeEmpresa,
                "login_domain" => $loginDomain,
            ],
        );
        $pdo->commit();
        responder_json(
            [
                "data" => [
                    "id" => $createdCompanyId,
                    "name" => $nomeEmpresa,
                    "login_domain" => $loginDomain,
                ],
            ],
            201,
        );
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            responder_json(["error" => "Já existe uma empresa com este nome."], 409);
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "PUT") {
    if ($usuarioAtor["role"] !== "ADMIN_DALLOGIX") {
        json_response(
            ["error" => "Somente o Administrador Dallogix pode renomear empresas."],
            403,
        );
    }

    require_csrf();
    $payload = ler_json_da_requisicao();
    $id = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
    $action = strtolower(trim((string) ($payload["action"] ?? "rename")));
    if (in_array($action, ["archive", "restore"], true)) {
        if (!$id) {
            json_response(["error" => "Empresa não informada."], 422);
        }
        $pdo = db();
        $empresa = $pdo->prepare(
            "SELECT id, name, login_domain, archived_at FROM empresas WHERE id = :id LIMIT 1",
        );
        $empresa->execute(["id" => $id]);
        $empresaAtual = $empresa->fetch();
        if (!$empresaAtual) {
            json_response(["error" => "Empresa não encontrada."], 404);
        }
        $arquivar = $action === "archive";
        $jaNoEstado = $arquivar
            ? $empresaAtual["archived_at"] !== null
            : $empresaAtual["archived_at"] === null;
        if (!$jaNoEstado) {
            $pdo->beginTransaction();
            try {
                $atualizacao = $pdo->prepare(
                    "UPDATE empresas SET archived_at = :archived_at WHERE id = :id",
                );
                $atualizacao->execute([
                    "archived_at" => $arquivar ? date("Y-m-d H:i:s") : null,
                    "id" => $id,
                ]);
                registrar_evento_operacional(
                    $pdo,
                    $usuarioAtor,
                    $arquivar ? "EMPRESA_ARQUIVADA" : "EMPRESA_RESTAURADA",
                    "company",
                    (int) $id,
                    [
                        "name" => $empresaAtual["name"],
                        "login_domain" => $empresaAtual["login_domain"],
                    ],
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        json_response([
            "data" => [
                "id" => (int) $id,
                "name" => $empresaAtual["name"],
                "login_domain" => $empresaAtual["login_domain"],
                "archived_at" => $arquivar
                    ? ($empresaAtual["archived_at"] ?: date("Y-m-d H:i:s"))
                    : null,
                "archived" => $arquivar,
            ],
        ]);
    }
    $nomeEmpresa = trim((string) ($payload["name"] ?? ""));
    if (!$id) {
        json_response(["error" => "Empresa não informada."], 422);
    }
    if (!nome_empresa_valido($nomeEmpresa)) {
        json_response(["error" => "Informe o nome da empresa."], 422);
    }

    $pdo = db();
    $empresa = $pdo->prepare("SELECT id, name, login_domain FROM empresas WHERE id = :id LIMIT 1");
    $empresa->execute(["id" => $id]);
    $empresaAtual = $empresa->fetch();
    if (!$empresaAtual) {
        json_response(["error" => "Empresa não encontrada."], 404);
    }
    $duplicada = $pdo->prepare(
        "SELECT id FROM empresas WHERE name = :name AND id <> :id LIMIT 1",
    );
    $duplicada->execute(["name" => $nomeEmpresa, "id" => $id]);
    if ($duplicada->fetch()) {
        json_response(["error" => "Já existe uma empresa com este nome."], 409);
    }

    $pdo->beginTransaction();
    try {
        $atualizacao = $pdo->prepare("UPDATE empresas SET name = :name WHERE id = :id");
        $atualizacao->execute(["name" => $nomeEmpresa, "id" => $id]);
        registrar_evento_operacional(
            $pdo,
            $usuarioAtor,
            "EMPRESA_RENOMEADA",
            "company",
            (int) $id,
            [
                "name_before" => $empresaAtual["name"],
                "name_after" => $nomeEmpresa,
                "login_domain" => $empresaAtual["login_domain"],
            ],
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    json_response([
        "data" => [
            "id" => (int) $id,
            "name" => $nomeEmpresa,
            "login_domain" => $empresaAtual["login_domain"],
        ],
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    if ($usuarioAtor["role"] !== "ADMIN_DALLOGIX") {
        json_response(
            ["error" => "Somente o Administrador Dallogix pode remover empresas."],
            403,
        );
    }

    require_csrf();
    $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(["error" => "Empresa não informada."], 422);
    }

    $exclusaoDefinitiva = ($_GET["permanent"] ?? "") === "1";
    $zerarEmpresa = ($_GET["force"] ?? "") === "1";

    $pdo = db();
    $find = $pdo->prepare("SELECT id, name, archived_at FROM empresas WHERE id = :id LIMIT 1");
    $find->execute(["id" => $id]);
    $empresa = $find->fetch();
    if (!$empresa) {
        json_response(["error" => "Empresa não encontrada."], 404);
    }
    if (!$exclusaoDefinitiva) {
        json_response(
            ["error" => "A remoção foi substituída por arquivamento. Arquive a empresa primeiro; a exclusão definitiva é uma ação separada."],
            409,
        );
    }
    if ($empresa["archived_at"] === null) {
        json_response(["error" => "Arquive a empresa antes de excluí-la definitivamente."], 409);
    }

    $payload = ler_json_da_requisicao();
    $confirmation = trim((string) ($payload["confirmation"] ?? ""));
    $password = (string) ($payload["password"] ?? "");
    if ($confirmation !== (string) $empresa["name"]) {
        json_response([
            "error" => "Digite exatamente o nome da empresa para confirmar a exclusão definitiva.",
        ], 422);
    }
    if ($password === "" || mb_strlen($password) > 128) {
        json_response(["error" => "A senha atual é obrigatória para excluir uma empresa."], 422);
    }
    $passwordStatement = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = :id LIMIT 1");
    $passwordStatement->execute(["id" => (int) ($usuarioAtor["id"] ?? 0)]);
    $passwordHash = (string) ($passwordStatement->fetchColumn() ?: "");
    if ($passwordHash === "" || !password_verify($password, $passwordHash)) {
        json_response(["error" => "Senha atual inválida."], 401);
    }

    $dependencias = [
        "usuarios" => "SELECT COUNT(*) FROM usuarios WHERE company_id = :id",
        "equipamentos" => "SELECT COUNT(*) FROM equipamentos WHERE company_id = :id",
        "produtos" => "SELECT COUNT(*) FROM produtos WHERE company_id = :id",
        "romaneios" => "SELECT COUNT(*) FROM romaneios WHERE company_id = :id",
        "carregamentos" => "SELECT COUNT(*) FROM carregamentos WHERE company_id = :id",
        "ocorrências" => "SELECT COUNT(*) FROM ocorrencias WHERE company_id = :id",
        "auditoria" => "SELECT COUNT(*) FROM logs_auditoria WHERE company_id = :id",
        "configurações" => "SELECT COUNT(*) FROM configuracoes_empresa WHERE company_id = :id",
        "licenças" => "SELECT COUNT(*) FROM licencas WHERE company_id = :id",
        "comandos industriais" => "SELECT COUNT(*) FROM solicitacoes_comandos_clp WHERE company_id = :id",
    ];

    $encontradas = [];
    foreach ($dependencias as $label => $query) {
        $statement = $pdo->prepare($query);
        $statement->execute(["id" => $id]);
        if ((int) $statement->fetchColumn() > 0) {
            $encontradas[] = $label;
        }
    }

    if ($encontradas) {
        if ($zerarEmpresa) {
            try {
                $pdo->beginTransaction();
                registrar_evento_operacional(
                    $pdo,
                    $usuarioAtor,
                    "EMPRESA_EXCLUIDA_DEFINITIVA",
                    "company",
                    (int) $id,
                    [
                        "target_company_id" => (int) $id,
                        "company_name" => (string) $empresa["name"],
                        "forced" => true,
                        "confirmation" => "nome_e_senha_validados",
                    ],
                );
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                remover_empresa_com_dados($pdo, (int) $id);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->commit();
                json_response(["data" => ["deleted" => true, "name" => $empresa["name"], "purged" => true]]);
            } catch (Throwable $exception) {
                try {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                } catch (Throwable $restoreException) {
                    error_log(
                        "Falha ao restaurar FOREIGN_KEY_CHECKS após erro ao excluir a empresa {$id}: "
                        . $restoreException->getMessage(),
                    );
                }
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Falha ao zerar empresa {$id}: " . $exception->getMessage());
                json_response(["error" => "Não foi possível zerar os dados da empresa."], 409);
            }
        }
        json_response(
            [
                "error" => "A empresa não pode ser removida porque possui dados vinculados: " . implode(", ", $encontradas) . ".",
            ],
            409,
        );
    }

    try {
        $pdo->beginTransaction();
        registrar_evento_operacional(
            $pdo,
            $usuarioAtor,
            "EMPRESA_EXCLUIDA_DEFINITIVA",
            "company",
            (int) $id,
            [
                "target_company_id" => (int) $id,
                "company_name" => (string) $empresa["name"],
                "forced" => false,
                "confirmation" => "nome_e_senha_validados",
            ],
        );
        $delete = $pdo->prepare("DELETE FROM empresas WHERE id = :id AND archived_at IS NOT NULL");
        $delete->execute(["id" => $id]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException("A empresa não pôde ser removida.");
        }
        $pdo->commit();
        json_response(["data" => ["deleted" => true, "name" => $empresa["name"], "purged" => false]]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(["error" => "Não foi possível remover a empresa com segurança."], 409);
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}

session_write_close();
$isAdminDallogix = $usuarioAtor["role"] === "ADMIN_DALLOGIX";
$rawCompanyId = trim((string) ($_GET["company_id"] ?? ""));
if ($rawCompanyId !== "" && filter_var($rawCompanyId, FILTER_VALIDATE_INT) === false) {
    json_response(["error" => "Identificador de empresa inválido."], 422);
}
$requestedCompanyId = $rawCompanyId === "" ? null : (int) $rawCompanyId;

if (!$isAdminDallogix) {
    if (
        $usuarioAtor["company_id"] === null ||
        ($requestedCompanyId !== null && $requestedCompanyId !== (int) $usuarioAtor["company_id"])
    ) {
        json_response(
            ["error" => "Perfil sem permissão para consultar esta empresa."],
            403,
        );
    }
    $requestedCompanyId = (int) $usuarioAtor["company_id"];
}

$pdo = db();
$availableStatusTables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN ('instalacoes_industriais', 'status_pc_industrial')",
)->fetchAll(PDO::FETCH_COLUMN);
$availableStatusTables = array_fill_keys($availableStatusTables, true);
$industrialInstallationsTableAvailable = isset($availableStatusTables["instalacoes_industriais"]);
$legacyIndustrialPcTableAvailable = isset($availableStatusTables["status_pc_industrial"]);
$pcSignalLimitSeconds = max(5, min(300, (int) (getenv("HEALTH_DEVICE_STALE_SECONDS") ?: 30)));
$industrialPcStatusSelect = $industrialInstallationsTableAvailable
    ? "(SELECT CASE
           WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = empresas.id
                        AND p.status = 'ONLINE' AND p.last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$pcSignalLimitSeconds} SECOND)) THEN 'ONLINE'
           WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = empresas.id AND p.status = 'ERRO') THEN 'ERRO'
           WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = empresas.id AND p.sync_token_hash IS NOT NULL) THEN 'OFFLINE'
           ELSE 'DESCONHECIDO' END)"
    : ($legacyIndustrialPcTableAvailable
        ? "(SELECT s.status FROM status_pc_industrial s WHERE s.company_id = empresas.id LIMIT 1)"
        : "NULL");
$industrialPcLastSeenSelect = $industrialInstallationsTableAvailable
    ? "(SELECT MAX(p.last_seen_at) FROM instalacoes_industriais p WHERE p.company_id = empresas.id)"
    : ($legacyIndustrialPcTableAvailable
        ? "(SELECT s.last_seen_at FROM status_pc_industrial s WHERE s.company_id = empresas.id LIMIT 1)"
        : "NULL");
$freshClpStatus = "CASE WHEN d.status = 'ONLINE' AND (d.last_seen_at IS NULL OR d.last_seen_at < DATE_SUB(NOW(3), INTERVAL {$pcSignalLimitSeconds} SECOND)) THEN 'OFFLINE' ELSE d.status END";
$industrialPcStatus = static function (mixed $reportedStatus, mixed $lastSeenAt) use ($pcSignalLimitSeconds): string {
    $status = strtoupper(trim((string) ($reportedStatus ?? "")));
    if ($status === "ERRO") {
        return "ERRO";
    }
    if ($status === "OFFLINE") {
        return "OFFLINE";
    }
    if ($status !== "ONLINE" || trim((string) ($lastSeenAt ?? "")) === "") {
        return "DESCONHECIDO";
    }
    try {
        $lastSeen = new DateTimeImmutable((string) $lastSeenAt, new DateTimeZone("UTC"));
        $age = max(0, time() - $lastSeen->getTimestamp());
        return $age <= $pcSignalLimitSeconds ? "ONLINE" : "OFFLINE";
    } catch (Throwable) {
        return "OFFLINE";
    }
};

$machinesSql = "SELECT e.id, e.equipment_code, e.name,
            {$freshClpStatus} AS clp_status, d.last_seen_at, d.details AS clp_details,
            c.state AS carregamento_state, r.number AS romaneio_number, rt.plate,
            COALESCE((SELECT SUM(ri.planned_quantity) FROM romaneio_itens ri WHERE ri.romaneio_id = c.romaneio_id AND (ri.truck_id = c.truck_id OR ri.truck_id IS NULL)), 0) AS planned_quantity,
            COALESCE(c.leituras_validas, 0) AS valid_readings
     FROM equipamentos e
     LEFT JOIN status_dispositivos d ON d.equipment_id = e.id AND d.device_type = 'CLP'
     LEFT JOIN carregamentos c ON c.id = (SELECT c2.id FROM carregamentos c2 WHERE c2.equipment_id = e.id AND c2.state <> 'FINALIZADO' AND EXISTS (SELECT 1 FROM romaneios r2 WHERE r2.id = c2.romaneio_id AND r2.status NOT IN ('FINALIZADO', 'CANCELADO')) ORDER BY c2.id DESC LIMIT 1)
     LEFT JOIN romaneios r ON r.id = c.romaneio_id
     LEFT JOIN romaneio_caminhoes rt ON rt.id = c.truck_id
     WHERE e.company_id = :company_id
     ORDER BY e.equipment_code";

if ($requestedCompanyId !== null) {
    $companyStatement = $pdo->prepare(
        "SELECT id, name, login_domain, activation_code, activation_code_preview, activation_code_created_at,
                activation_code_expires_at, activation_code_used_at,
                (activation_code_expires_at IS NOT NULL AND activation_code_expires_at > NOW() AND activation_code_used_at IS NULL) AS activation_code_pending,
                created_at, archived_at,
                (SELECT l.status FROM licencas l WHERE l.company_id = empresas.id ORDER BY l.id DESC LIMIT 1) AS license_status,
                {$industrialPcStatusSelect} AS industrial_pc_reported_status,
                {$industrialPcLastSeenSelect} AS industrial_pc_last_seen_at
         FROM empresas WHERE id = :id LIMIT 1",
    );
    $companyStatement->execute(["id" => $requestedCompanyId]);
    $empresa = $companyStatement->fetch();
    if (!$empresa) {
        json_response(["error" => "Empresa não encontrada."], 404);
    }

    $maquinas = $pdo->prepare($machinesSql);
    $maquinas->execute(["company_id" => $requestedCompanyId]);
    $maquinasData = array_map(static function (array $machine): array {
        $machine["physical_running"] = EstadoFisicoClp::estaEmFuncionamentoPelosDetalhes(
            $machine["clp_details"] ?? null,
        );
        unset($machine["clp_details"]);
        return $machine;
    }, $maquinas->fetchAll());

    $ocorrencias = $pdo->prepare(
        "SELECT type, quantity, description, created_at FROM ocorrencias WHERE company_id = :company_id ORDER BY id DESC LIMIT 10",
    );
    $ocorrencias->execute(["company_id" => $requestedCompanyId]);

    $romaneios = $pdo->prepare(
        "SELECT status, COUNT(*) AS total FROM romaneios WHERE company_id = :company_id GROUP BY status",
    );
    $romaneios->execute(["company_id" => $requestedCompanyId]);
    $resumoRomaneios = [];
    foreach ($romaneios->fetchAll() as $row) {
        $resumoRomaneios[$row["status"]] = (int) $row["total"];
    }

    $sincronizacaoPendente = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM fila_sincronizacao q WHERE q.company_id = :company_id AND q.status IN ('PENDENTE', 'ERRO', 'PROCESSANDO')",
    );
    $sincronizacaoPendente->execute(["company_id" => $requestedCompanyId]);

    $licenseStatus = $empresa["license_status"] ?: "SEM_LICENCA";
    $activationAvailable = $licenseStatus === "ATIVA";
    json_response([
        "data" => [
            "id" => (int) $empresa["id"],
            "name" => $empresa["name"],
            "login_domain" => $empresa["login_domain"],
            "license_status" => $licenseStatus,
            "industrial_pc_status" => $industrialPcStatus(
                $empresa["industrial_pc_reported_status"] ?? null,
                $empresa["industrial_pc_last_seen_at"] ?? null,
            ),
            "industrial_pc_last_seen_at" => $empresa["industrial_pc_last_seen_at"] ?? null,
            "activation_code" => $isAdminDallogix && $activationAvailable && (bool) $empresa["activation_code_pending"] ? $empresa["activation_code"] : null,
            "activation_code_preview" => $activationAvailable && (bool) $empresa["activation_code_pending"] ? $empresa["activation_code_preview"] : null,
            "activation_code_created_at" => $activationAvailable ? $empresa["activation_code_created_at"] : null,
            "activation_code_expires_at" => $activationAvailable && (bool) $empresa["activation_code_pending"] ? $empresa["activation_code_expires_at"] : null,
            "activation_code_used_at" => $activationAvailable ? $empresa["activation_code_used_at"] : null,
            "created_at" => $empresa["created_at"],
            "archived_at" => $empresa["archived_at"],
            "archived" => $empresa["archived_at"] !== null,
            "maquinas" => $maquinasData,
            "ocorrencias_recentes" => $ocorrencias->fetchAll(),
            "romaneios" => $resumoRomaneios,
            "sync_pendente" => (int) ($sincronizacaoPendente->fetch()["total"] ?? 0),
        ],
    ]);
}

$includeArchived = $isAdminDallogix && ($_GET["include_archived"] ?? "") === "1";
$pagination = parametros_paginacao_lista($_GET);
$empresas = $pdo->prepare(
    "SELECT c.id, c.name, c.login_domain, c.activation_code_preview, c.activation_code_created_at,
            c.activation_code_expires_at, c.activation_code_used_at,
            (c.activation_code_expires_at IS NOT NULL AND c.activation_code_expires_at > NOW() AND c.activation_code_used_at IS NULL) AS activation_code_pending,
            c.created_at, c.archived_at,
            (SELECT l.status FROM licencas l WHERE l.company_id = c.id ORDER BY l.id DESC LIMIT 1) AS license_status,
            (SELECT l.blocked_reason FROM licencas l WHERE l.company_id = c.id ORDER BY l.id DESC LIMIT 1) AS license_reason,
            " . ($industrialInstallationsTableAvailable
                ? "(SELECT CASE
                       WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = c.id
                                    AND p.status = 'ONLINE' AND p.last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$pcSignalLimitSeconds} SECOND)) THEN 'ONLINE'
                       WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = c.id AND p.status = 'ERRO') THEN 'ERRO'
                       WHEN EXISTS (SELECT 1 FROM instalacoes_industriais p WHERE p.company_id = c.id AND p.sync_token_hash IS NOT NULL) THEN 'OFFLINE'
                       ELSE 'DESCONHECIDO' END)"
                : ($legacyIndustrialPcTableAvailable
                    ? "(SELECT s.status FROM status_pc_industrial s WHERE s.company_id = c.id LIMIT 1)"
                    : "NULL")) . " AS industrial_pc_reported_status,
            " . ($industrialInstallationsTableAvailable
                ? "(SELECT MAX(p.last_seen_at) FROM instalacoes_industriais p WHERE p.company_id = c.id)"
                : ($legacyIndustrialPcTableAvailable
                    ? "(SELECT s.last_seen_at FROM status_pc_industrial s WHERE s.company_id = c.id LIMIT 1)"
                    : "NULL")) . " AS industrial_pc_last_seen_at,
            COUNT(e.id) AS total_machines,
            COALESCE(SUM(d.status = 'ONLINE' AND d.last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$pcSignalLimitSeconds} SECOND)), 0) AS machines_online,
            MAX(d.last_seen_at) AS last_signal_at,
            (SELECT COUNT(*) FROM usuarios u WHERE u.company_id = c.id) AS total_users,
            (SELECT COUNT(*) FROM usuarios u WHERE u.company_id = c.id AND u.active = 1) AS active_users,
            (SELECT COUNT(*) FROM ocorrencias o WHERE o.company_id = c.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS ocorrencias_24h
     FROM empresas c
     LEFT JOIN equipamentos e ON e.company_id = c.id
     LEFT JOIN status_dispositivos d ON d.equipment_id = e.id AND d.device_type = 'CLP'
     WHERE (:include_archived = 1 OR c.archived_at IS NULL)
       AND c.id > :cursor
     GROUP BY c.id, c.name, c.login_domain, c.created_at, c.archived_at
     ORDER BY c.id LIMIT :fetch_limit",
);
$empresas->bindValue(":include_archived", $includeArchived ? 1 : 0, PDO::PARAM_INT);
$empresas->bindValue(":cursor", $pagination["cursor"], PDO::PARAM_INT);
$empresas->bindValue(":fetch_limit", $pagination["limit"] + 1, PDO::PARAM_INT);
$empresas->execute();
$rows = array_map(static function (array $row) use ($industrialPcStatus): array {
    $total = (int) $row["total_machines"];
    $online = (int) $row["machines_online"];
    $licenseStatus = $row["license_status"] ?: "SEM_LICENCA";
    return [
        "id" => (int) $row["id"],
        "name" => $row["name"],
        "login_domain" => $row["login_domain"],
        "activation_code_preview" => $licenseStatus === "ATIVA" && (bool) $row["activation_code_pending"] ? $row["activation_code_preview"] : null,
        "activation_code_created_at" => $row["activation_code_created_at"],
        "activation_code_expires_at" => $row["activation_code_pending"] ? $row["activation_code_expires_at"] : null,
        "created_at" => $row["created_at"],
        "archived_at" => $row["archived_at"],
        "archived" => $row["archived_at"] !== null,
        "total_machines" => $total,
        "machines_online" => $online,
        "machines_offline" => max(0, $total - $online),
        "last_signal_at" => $row["last_signal_at"],
        "industrial_pc_status" => $industrialPcStatus(
            $row["industrial_pc_reported_status"] ?? null,
            $row["industrial_pc_last_seen_at"] ?? null,
        ),
        "industrial_pc_last_seen_at" => $row["industrial_pc_last_seen_at"] ?? null,
        "total_users" => (int) $row["total_users"],
        "active_users" => (int) $row["active_users"],
        "ocorrencias_24h" => (int) $row["ocorrencias_24h"],
        "license_status" => $licenseStatus,
        "license_reason" => $row["license_reason"],
    ];
}, $empresas->fetchAll(PDO::FETCH_ASSOC));

$page = pagina_lista_por_id($rows, $pagination["limit"]);
json_response($page);
