<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/InicializadorAcoesDala.php";
require_once __DIR__ . "/../../src/Aplicacao/ProvisionadorDispositivosLocais.php";
require_once __DIR__ . "/../../src/Aplicacao/ValidadorCodigoDala.php";

use App\Aplicacao\InicializadorAcoesDala;
use App\Aplicacao\ProvisionadorDispositivosLocais;
use App\Aplicacao\ValidadorCodigoDala;

$user = require_session_user();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = db();
$canManage = $user["role"] === "ADMIN_EMPRESA";
$canDelete = $user["role"] === "ADMIN_EMPRESA";

// Verificação de conectividade TCP. Na instalação local, o destino vem sempre
// do IP/nome e porta da Dala cadastrada. Isso não confirma o protocolo Modbus.
if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    ($_GET["check"] ?? "") === "status"
) {
    $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(["error" => "Dala não informada."], 422);
    }
    $statement = $pdo->prepare(
        "SELECT e.id, e.equipment_code, e.plc_ip, e.plc_port
         FROM equipamentos e WHERE e.id = :id AND e.company_id = :company_id LIMIT 1",
    );
    $statement->execute(["id" => $id, "company_id" => $user["company_id"]]);
    $dala = $statement->fetch();
    if (!$dala) {
        json_response(["error" => "Dala não encontrada."], 404);
    }
    $host = (string) $dala["plc_ip"];
    $port = (int) $dala["plc_port"];
    if (!$host || $port < 1) {
        json_response([
            "data" => [
                "status" => "OFFLINE",
                "message" => "Dala sem endereço de comunicação configurado.",
            ],
        ]);
    }
    $resolvedHost = trace_e_instalacao_local() ? resolver_destino_clp_local($host, $port) : $host;
    if ($resolvedHost === null) {
        json_response([
            "data" => [
                "status" => "OFFLINE",
                "message" => "Endereço do CLP inválido ou fora da rede privada da instalação.",
            ],
        ]);
    }
    $staleSeconds = limite_sinal_clp_segundos();
    $reported = $pdo->prepare(
        "SELECT status, last_seen_at, details,
                CASE WHEN status = 'ONLINE'
                  AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$staleSeconds} SECOND)
                  THEN 1 ELSE 0 END AS heartbeat_recente
         FROM status_dispositivos
         WHERE equipment_id = :equipment_id AND device_type = 'CLP' LIMIT 1",
    );
    $reported->execute(["equipment_id" => $id]);
    $heartbeat = $reported->fetch() ?: [];
    $details = is_string($heartbeat["details"] ?? null)
        ? json_decode((string) $heartbeat["details"], true)
        : ($heartbeat["details"] ?? null);
    $modbusValido = (int) ($heartbeat["heartbeat_recente"] ?? 0) === 1
        && heartbeat_modbus_valido($details, (string) $resolvedHost, $port);
    if ($modbusValido) {
        $funcao = (int) ($details["modbus_function"] ?? 0);
        $registrador = (int) ($details["diagnostic_register"] ?? 0);
        $unidade = (int) ($details["modbus_unit_id"] ?? 0);
        json_response([
            "data" => [
                "status" => "ONLINE",
                "message" => "Modbus verificado no endereço {$host}:{$port} (unidade {$unidade}, FC{$funcao}, registrador {$registrador}).",
                "target" => "{$host}:{$port}",
                "verification" => [
                    "protocol" => "MODBUS_TCP",
                    "unit" => $unidade,
                    "function" => $funcao,
                    "register" => $registrador,
                    "last_seen_at" => $heartbeat["last_seen_at"] ?? null,
                ],
            ],
        ]);
    }

    $tcpReachable = false;
    if (trace_e_instalacao_local()) {
        $connection = @fsockopen($resolvedHost, $port, $errno, $errstr, 2.0);
        if ($connection) {
            fclose($connection);
            $tcpReachable = true;
        }
    }
    $message = $tcpReachable
        ? "Conexão TCP aberta, mas nenhuma resposta Modbus válida foi confirmada para {$host}:{$port}."
        : "Nenhuma resposta Modbus válida foi confirmada para {$host}:{$port}.";
    json_response([
        "data" => [
            "status" => "OFFLINE",
            "message" => $message,
            "target" => "{$host}:{$port}",
            "verification" => [
                "protocol" => "MODBUS_TCP",
                "confirmed" => false,
                "tcp_reachable" => $tcpReachable,
                "last_seen_at" => $heartbeat["last_seen_at"] ?? null,
            ],
        ],
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        $statement = $pdo->prepare(
            "SELECT id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol, created_at, updated_at FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
        );
        $statement->execute(["id" => $id, "company_id" => $user["company_id"]]);
        $dala = $statement->fetch();
        if (!$dala) {
            json_response(["error" => "Dala não encontrada."], 404);
        }
        json_response(["data" => $dala]);
    }
    $pagination = parametros_paginacao_lista($_GET);
    $pcSignalLimitSeconds = max(5, min(300, (int) (getenv("HEALTH_DEVICE_STALE_SECONDS") ?: 30)));
    $query = trace_e_instalacao_local()
        ? $pdo->prepare(
            "SELECT id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol,
                    created_at, updated_at
             FROM equipamentos
             WHERE company_id = :company_id AND id > :cursor
             ORDER BY id LIMIT :fetch_limit",
        )
        : $pdo->prepare(
            "SELECT e.id, e.equipment_code, e.name, e.plc_ip, e.plc_port, e.external_port,
                    e.plc_protocol, e.created_at, e.updated_at,
                    p.name AS industrial_pc_name, p.id AS industrial_pc_id,
                    CASE WHEN p.status = 'ONLINE' AND (p.last_seen_at IS NULL
                         OR p.last_seen_at < DATE_SUB(NOW(3), INTERVAL {$pcSignalLimitSeconds} SECOND))
                         THEN 'OFFLINE' ELSE p.status END AS industrial_pc_status,
                    p.last_seen_at AS industrial_pc_last_seen_at
             FROM equipamentos e
             LEFT JOIN instalacoes_industriais p
               ON p.equipment_id = e.id AND p.company_id = e.company_id
             WHERE e.company_id = :company_id AND e.id > :cursor
             ORDER BY e.id LIMIT :fetch_limit",
        );
    $query->bindValue(":company_id", (int) $user["company_id"], PDO::PARAM_INT);
    $query->bindValue(":cursor", $pagination["cursor"], PDO::PARAM_INT);
    $query->bindValue(":fetch_limit", $pagination["limit"] + 1, PDO::PARAM_INT);
    $query->execute();
    json_response(pagina_lista_por_id($query->fetchAll(PDO::FETCH_ASSOC), $pagination["limit"]));
}

$validatePayload = static function (array $payload): array {
    $code = trim((string) ($payload["equipment_code"] ?? ""));
    $name = trim((string) ($payload["name"] ?? ""));
    $ip = trim((string) ($payload["plc_ip"] ?? ""));
    $port = filter_var($payload["plc_port"] ?? null, FILTER_VALIDATE_INT);
    $externalPort =
        ($payload["external_port"] ?? "") === "" ||
        $payload["external_port"] === null
            ? null
            : filter_var($payload["external_port"], FILTER_VALIDATE_INT);
    $protocol = strtoupper(
        trim((string) ($payload["plc_protocol"] ?? "MODBUS_TCP")),
    );
    if (!ValidadorCodigoDala::ehValido($code)) {
        json_response(
            [
                "error" =>
                    "Identificador inválido: use letras, números, hífen ou underscore (máx. 30 caracteres).",
            ],
            422,
        );
    }
    if (
        $name === "" ||
        $ip === "" ||
        !in_array($protocol, ["MODBUS_TCP", "MODBUS_RTU"], true) ||
        $port === false ||
        $port < 1 ||
        $port > 65535 ||
        ($externalPort !== null &&
            ($externalPort === false ||
                $externalPort < 1 ||
                $externalPort > 65535))
    ) {
        json_response(
            ["error" => "Dados da Dala ou comunicação inválidos."],
            422,
        );
    }
    return [
        "code" => $code,
        "name" => $name,
        "ip" => $ip,
        "port" => $port,
        "externalPort" => $externalPort,
        "protocol" => $protocol,
    ];
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_csrf();
    if (!$canManage) {
        json_response(
            ["error" => "Perfil sem permissão para cadastrar Dala."],
            403,
        );
    }
    $data = $validatePayload(request_json());
    $pdo->beginTransaction();
    try {
        if (trace_e_instalacao_local()) {
            $pdo->query("SELECT id FROM instalacoes_locais WHERE id = 1 FOR UPDATE");
            $existing = $pdo->prepare(
                "SELECT id FROM equipamentos WHERE company_id = :company_id LIMIT 1",
            );
            $existing->execute(["company_id" => $user["company_id"]]);
            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException("LOCAL_DALA_LIMIT");
            }
        }
        $insert = $pdo->prepare(
            "INSERT INTO equipamentos (company_id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol) VALUES (:company_id, :equipment_code, :name, :plc_ip, :plc_port, :external_port, :plc_protocol)",
        );
        $insert->execute([
            "company_id" => $user["company_id"],
            "equipment_code" => $data["code"],
            "name" => $data["name"],
            "plc_ip" => $data["ip"],
            "plc_port" => $data["port"],
            "external_port" => $data["externalPort"],
            "plc_protocol" => $data["protocol"],
        ]);
        $id = (int) $pdo->lastInsertId();
        InicializadorAcoesDala::garantir($pdo, (int) $user["company_id"], $id);
        ProvisionadorDispositivosLocais::garantir(
            $pdo,
            (int) $user["company_id"],
            $id,
            $data["code"],
        );
        record_operational_event(
            $pdo,
            $user,
            "DALA_CADASTRADA",
            "equipment",
            $id,
            [
                "equipment_code" => $data["code"],
                "name" => $data["name"],
                "plc_ip" => $data["ip"],
                "plc_port" => $data["port"],
                "external_port" => $data["externalPort"],
                "plc_protocol" => $data["protocol"],
            ],
        );
        $pdo->commit();
        json_response(
            ["data" => ["id" => $id, "equipment_code" => $data["code"]]],
            201,
        );
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(["error" => "Identificador da Dala já cadastrado."], 409);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException && $exception->getMessage() === "LOCAL_DALA_LIMIT") {
            json_response(["error" => "Este PC industrial já possui uma Dala cadastrada."], 409);
        }
        throw $exception;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "PUT") {
    require_csrf();
    if (!$canManage) {
        json_response(
            ["error" => "Perfil sem permissão para editar Dala."],
            403,
        );
    }
    $payload = request_json();
    $id = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(["error" => "Dala não informada."], 422);
    }
    $find = $pdo->prepare(
        "SELECT id, equipment_code FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
    );
    $find->execute(["id" => $id, "company_id" => $user["company_id"]]);
    $existingDala = $find->fetch();
    if (!$existingDala) {
        json_response(["error" => "Dala não encontrada."], 404);
    }
    $data = $validatePayload($payload);
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            "UPDATE equipamentos SET equipment_code = :equipment_code, name = :name, plc_ip = :plc_ip, plc_port = :plc_port, external_port = :external_port, plc_protocol = :plc_protocol WHERE id = :id AND company_id = :company_id",
        );
        $update->execute([
            "equipment_code" => $data["code"],
            "name" => $data["name"],
            "plc_ip" => $data["ip"],
            "plc_port" => $data["port"],
            "external_port" => $data["externalPort"],
            "plc_protocol" => $data["protocol"],
            "id" => $id,
            "company_id" => $user["company_id"],
        ]);
        // O último heartbeat pode pertencer ao IP anterior. Invalide-o para
        // que a interface aguarde uma leitura Modbus válida do novo destino.
        $pdo->prepare(
            "DELETE FROM status_dispositivos
             WHERE equipment_id = :equipment_id AND device_type = 'CLP'",
        )->execute(["equipment_id" => $id]);
        ProvisionadorDispositivosLocais::garantir(
            $pdo,
            (int) $user["company_id"],
            (int) $id,
            $data["code"],
        );
        record_operational_event(
            $pdo,
            $user,
            "DALA_ATUALIZADA",
            "equipment",
            $id,
            [
                "previous_equipment_code" => $existingDala["equipment_code"],
                "equipment_code" => $data["code"],
                "name" => $data["name"],
                "plc_ip" => $data["ip"],
                "plc_port" => $data["port"],
                "external_port" => $data["externalPort"],
                "plc_protocol" => $data["protocol"],
            ],
        );
        $pdo->commit();
        json_response(["data" => ["updated" => true]]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(["error" => "Identificador da Dala já cadastrado."], 409);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    require_csrf();
    if (!$canDelete) {
        json_response(
            ["error" => "Somente administradores podem excluir Dala."],
            403,
        );
    }
    $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(["error" => "Dala não informada."], 422);
    }
    $find = $pdo->prepare(
        "SELECT id, equipment_code FROM equipamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
    );
    $find->execute(["id" => $id, "company_id" => $user["company_id"]]);
    $dala = $find->fetch();
    if (!$dala) {
        json_response(["error" => "Dala não encontrada."], 404);
    }
    try {
        $pdo->beginTransaction();
        $activeLoadings = $pdo->prepare(
            "SELECT c.id, c.state, c.remote_carregamento_id
             FROM carregamentos c
             JOIN romaneios r ON r.id = c.romaneio_id
             WHERE c.company_id = :company_id AND c.equipment_id = :equipment_id
               AND c.state <> 'FINALIZADO'
               AND r.status NOT IN ('FINALIZADO', 'CANCELADO')
             ORDER BY c.id FOR UPDATE",
        );
        $activeLoadings->execute([
            "company_id" => $user["company_id"],
            "equipment_id" => $id,
        ]);
        foreach ($activeLoadings->fetchAll() as $loading) {
            $detach = $pdo->prepare(
                "UPDATE carregamentos
                 SET equipment_id = NULL, state = 'AGUARDANDO', started_at = NULL
                 WHERE id = :id AND company_id = :company_id",
            );
            $detach->execute([
                "id" => $loading["id"],
                "company_id" => $user["company_id"],
            ]);
            record_operational_event(
                $pdo,
                $user,
                "CARREGAMENTO_DALA_DESVINCULADO",
                "carregamento",
                (int) $loading["id"],
                [
                    "previous_equipment_id" => (int) $id,
                    "equipment_code" => $dala["equipment_code"],
                    "previous_state" => $loading["state"],
                    "state" => "AGUARDANDO",
                    "remote_carregamento_id" => $loading["remote_carregamento_id"] === null ? null : (int) $loading["remote_carregamento_id"],
                ],
            );
        }
        // O histórico operacional permanece preservado, mas não pode manter
        // uma referência a uma Dala que será removida. A consulta acima trata
        // somente carregamentos ativos; os finalizados também precisam ser
        // desvinculados antes da exclusão para não bloquear a FK.
        $detachHistoricalLoadings = $pdo->prepare(
            "UPDATE carregamentos
             SET equipment_id = NULL
             WHERE company_id = :company_id AND equipment_id = :equipment_id",
        );
        $detachHistoricalLoadings->execute([
            "company_id" => $user["company_id"],
            "equipment_id" => $id,
        ]);
        $pdo->prepare(
            "UPDATE solicitacoes_comandos_clp
             SET equipment_id = NULL, status = CASE WHEN status IN ('PENDENTE', 'PROCESSANDO') THEN 'ERRO' ELSE status END,
                 completed_at = COALESCE(completed_at, NOW(3)),
                 response_message = COALESCE(response_message, 'Dala excluída antes da conclusão do comando.')
             WHERE company_id = :company_id AND equipment_id = :equipment_id",
        )->execute(["company_id" => $user["company_id"], "equipment_id" => $id]);
        $pdo->prepare(
            "UPDATE solicitacoes_captura_camera
             SET equipment_id = NULL, status = CASE WHEN status IN ('PENDENTE', 'CAPTURANDO') THEN 'DESCARTADA' ELSE status END,
                 error_message = COALESCE(error_message, 'Dala excluída antes da captura.')
             WHERE equipment_id = :equipment_id",
        )->execute(["equipment_id" => $id]);
        $pdo->prepare("UPDATE eventos_sensor SET equipment_id = NULL WHERE equipment_id = :equipment_id")
            ->execute(["equipment_id" => $id]);
        $pdo->prepare("UPDATE imagens SET equipment_id = NULL WHERE equipment_id = :equipment_id")
            ->execute(["equipment_id" => $id]);
        $pdo->prepare("DELETE FROM dispositivos WHERE company_id = :company_id AND equipment_id = :equipment_id")
            ->execute(["company_id" => $user["company_id"], "equipment_id" => $id]);
        // As ações e o gatilho padrão são criados junto com toda Dala nova.
        // Eles não podem permanecer apontando para um equipamento removido.
        $pdo->prepare("DELETE FROM gatilhos_dala WHERE equipment_id = :id")->execute([
            "id" => $id,
        ]);
        $pdo->prepare("DELETE FROM acoes_dala WHERE equipment_id = :id")->execute([
            "id" => $id,
        ]);
        $pdo->prepare(
            "DELETE FROM status_dispositivos WHERE equipment_id = :id",
        )->execute(["id" => $id]);
        $pdo->prepare("DELETE FROM equipamentos WHERE id = :id AND company_id = :company_id")->execute([
            "id" => $id,
            "company_id" => $user["company_id"],
        ]);
        record_operational_event(
            $pdo,
            $user,
            "DALA_EXCLUIDA",
            "equipment",
            $id,
            [
                "remote_equipment_id" => $id,
                "equipment_code" => $dala["equipment_code"],
            ],
        );
        $pdo->commit();
        json_response(["data" => ["deleted" => true]]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Equipment deletion failed: " . $exception->getMessage());
        if ((int) $exception->errorInfo[1] === 1451) {
            json_response(
                [
                    "error" =>
                        "Dala possui carregamentos ou eventos vinculados e não pode ser excluída.",
                ],
                409,
            );
        }
        json_response(["error" => "Não foi possível excluir a Dala."], 500);
    }
}

json_response(["error" => "Método não permitido."], 405);
