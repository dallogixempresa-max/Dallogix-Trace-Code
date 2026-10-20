<?php
declare(strict_types=1);

require_once __DIR__ . "/_bootstrap.php";

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
if (!in_array($method, ["GET", "POST"], true)) {
    api_v1_responder_erro("method_not_allowed", "Método não permitido para a API v1.", 405);
}
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

$requestPath = parse_url((string) ($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH);
$prefix = "/api/v1";
if (!is_string($requestPath) || ($requestPath !== $prefix && !str_starts_with($requestPath, $prefix . "/"))) {
    api_v1_responder_erro("not_found", "Rota da API não encontrada.", 404);
}
$route = trim(substr($requestPath, strlen($prefix)), "/");
if ($method === "POST" && $route !== "romaneios") {
    api_v1_responder_erro("method_not_allowed", "A API só aceita gravação na criação de romaneios.", 405);
}
if ($route === "") {
    header("Content-Type: application/json; charset=utf-8");
    responder_json([
        "name" => "Dallogix Trace API",
        "version" => "v1",
        "mode" => "read_write_business_data",
        "openapi" => "/api/v1/openapi.yaml",
    ]);
}

if ($route === "openapi.yaml") {
    $openApiPath = __DIR__ . "/openapi.yaml";
    if (!is_file($openApiPath) || is_link($openApiPath)) {
        api_v1_responder_erro("documentation_unavailable", "A especificação da API não está disponível.", 404);
    }
    header("Content-Type: application/yaml; charset=utf-8");
    header("Content-Disposition: inline; filename=trace-api-v1.yaml");
    readfile($openApiPath);
    exit();
}

$pdo = obter_conexao_banco();

if (preg_match('/\Aromaneios\/([1-9][0-9]*)\/relatorio\.pdf\z/', $route, $matches) === 1) {
    $client = api_v1_exigir_cliente(["relatorios:read"]);
    $GLOBALS["trace_api_v1_client"] = $client;
    $_GET["romaneio_id"] = api_v1_validar_id($matches[1]);
    require __DIR__ . "/../relatorios/relatorio_auditoria.php";
    exit();
}

if ($route === "empresa") {
    $client = api_v1_exigir_cliente(["empresa:read"]);
    $statement = $pdo->prepare("SELECT id, name FROM empresas WHERE id = :company_id AND archived_at IS NULL LIMIT 1");
    $statement->execute(["company_id" => $client["company_id"]]);
    $company = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($company)) {
        api_v1_responder_erro("not_found", "Empresa não encontrada.", 404);
    }
    $company["id"] = (int) $company["id"];
    header("Content-Type: application/json; charset=utf-8");
    responder_json(["data" => $company]);
}

if ($route === "romaneios") {
    if ($method === "POST") {
        $client = api_v1_exigir_cliente(["romaneios:write"]);
        require __DIR__ . "/romaneios-write.php";
        api_v1_criar_romaneio($pdo, $client);
    }

    $client = api_v1_exigir_cliente(["romaneios:read"]);
    $pagination = api_v1_paginacao();
    $conditions = ["r.company_id = :company_id", "r.id > :cursor"];
    $params = ["company_id" => $client["company_id"], "cursor" => $pagination["cursor"]];
    $dateFrom = api_v1_validar_data(isset($_GET["date_from"]) ? (string) $_GET["date_from"] : null, "date_from");
    $dateTo = api_v1_validar_data(isset($_GET["date_to"]) ? (string) $_GET["date_to"] : null, "date_to");
    $rawNumber = $_GET["number"] ?? "";
    if (!is_string($rawNumber)) {
        api_v1_responder_erro("invalid_filter", "number deve ser texto.", 422);
    }
    $number = trim($rawNumber);
    $status = strtoupper(trim((string) ($_GET["status"] ?? "")));
    $allowedStatuses = ["IMPORTADO", "AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];
    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        api_v1_responder_erro("invalid_filter", "date_from não pode ser posterior a date_to.", 422);
    }
    if ($number !== "" && (mb_strlen($number, "UTF-8") > 80
        || preg_match('/^[A-Za-z0-9À-ÿ _.,\/-]{1,80}$/u', $number) !== 1)) {
        api_v1_responder_erro("invalid_filter", "number deve conter até 80 caracteres válidos.", 422);
    }
    if ($status !== "" && !in_array($status, $allowedStatuses, true)) {
        api_v1_responder_erro("invalid_filter", "status não reconhecido.", 422);
    }
    if ($dateFrom !== null) {
        $conditions[] = "r.scheduled_date >= :date_from";
        $params["date_from"] = $dateFrom;
    }
    if ($dateTo !== null) {
        $conditions[] = "r.scheduled_date <= :date_to";
        $params["date_to"] = $dateTo;
    }
    if ($status !== "") {
        $conditions[] = "r.status = :status";
        $params["status"] = $status;
    }
    if ($number !== "") {
        $conditions[] = "r.number = :number";
        $params["number"] = $number;
    }
    $sql = "SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor, ae.equipment_code,
                   r.created_at, r.updated_at,
                   (SELECT COUNT(*) FROM romaneio_caminhoes rt WHERE rt.romaneio_id = r.id) AS trucks_count,
                   COALESCE((SELECT SUM(ri.planned_quantity) FROM romaneio_itens ri WHERE ri.romaneio_id = r.id), 0) AS planned_quantity
            FROM romaneios r
            LEFT JOIN instalacoes_industriais ai
              ON ai.id = r.assigned_installation_id AND ai.company_id = r.company_id
            LEFT JOIN equipamentos ae ON ae.id = ai.equipment_id AND ae.company_id = r.company_id
            WHERE " . implode(" AND ", $conditions) . "
            ORDER BY r.id ASC LIMIT :fetch_limit";
    api_v1_responder_lista($pdo, $sql, $params, $pagination["limit"]);
}

if (preg_match('/\Aromaneios\/([1-9][0-9]*)\z/', $route, $matches) === 1) {
    $client = api_v1_exigir_cliente(["romaneios:read"]);
    $romaneioId = api_v1_validar_id($matches[1]);
    $statement = $pdo->prepare(
        "SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor, ae.equipment_code,
                r.created_at, r.updated_at
         FROM romaneios r
         LEFT JOIN instalacoes_industriais ai
           ON ai.id = r.assigned_installation_id AND ai.company_id = r.company_id
         LEFT JOIN equipamentos ae ON ae.id = ai.equipment_id AND ae.company_id = r.company_id
         WHERE r.id = :id AND r.company_id = :company_id LIMIT 1",
    );
    $statement->execute(["id" => $romaneioId, "company_id" => $client["company_id"]]);
    $romaneio = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($romaneio)) {
        api_v1_responder_erro("not_found", "Romaneio não encontrado.", 404);
    }
    $items = $pdo->prepare(
        "SELECT ri.id, ri.product_id, p.code AS product_code, p.name AS product_name,
                ri.truck_id, ri.planned_quantity
         FROM romaneio_itens ri
         JOIN produtos p ON p.id = ri.product_id AND p.company_id = :company_id
         WHERE ri.romaneio_id = :romaneio_id ORDER BY ri.id",
    );
    $items->execute(["company_id" => $client["company_id"], "romaneio_id" => $romaneioId]);
    $romaneio["items"] = $items->fetchAll(PDO::FETCH_ASSOC);
    $trucks = $pdo->prepare(
        "SELECT id, plate, driver_name FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id ORDER BY id",
    );
    $trucks->execute(["romaneio_id" => $romaneioId]);
    $romaneio["trucks"] = $trucks->fetchAll(PDO::FETCH_ASSOC);
    $romaneio["id"] = (int) $romaneio["id"];
    header("Content-Type: application/json; charset=utf-8");
    responder_json(["data" => $romaneio]);
}

if ($route === "carregamentos") {
    $client = api_v1_exigir_cliente(["carregamentos:read"]);
    $pagination = api_v1_paginacao();
    $conditions = ["c.company_id = :company_id", "c.id > :cursor"];
    $params = ["company_id" => $client["company_id"], "cursor" => $pagination["cursor"]];
    $state = strtoupper(trim((string) ($_GET["state"] ?? "")));
    $allowedStates = ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "FINALIZADO", "EMERGENCIA"];
    if ($state !== "" && !in_array($state, $allowedStates, true)) {
        api_v1_responder_erro("invalid_filter", "state não reconhecido.", 422);
    }
    if ($state !== "") {
        $conditions[] = "c.state = :state";
        $params["state"] = $state;
    }
    $sql = "SELECT c.id, c.state, c.romaneio_id, r.number AS romaneio_number,
                   c.truck_id, t.plate, c.equipment_id, e.equipment_code, e.name AS equipment_name,
                   c.started_at, c.finished_at, c.created_at, c.updated_at,
                   COALESCE(c.leituras_validas, 0) AS valid_readings,
                   COALESCE((SELECT SUM(ri.planned_quantity) FROM romaneio_itens ri
                             WHERE ri.romaneio_id = c.romaneio_id
                               AND (ri.truck_id = c.truck_id OR ri.truck_id IS NULL)), 0) AS planned_quantity
            FROM carregamentos c
            JOIN romaneios r ON r.id = c.romaneio_id AND r.company_id = c.company_id
            LEFT JOIN romaneio_caminhoes t ON t.id = c.truck_id AND t.romaneio_id = c.romaneio_id
            LEFT JOIN equipamentos e ON e.id = c.equipment_id AND e.company_id = c.company_id
            WHERE " . implode(" AND ", $conditions) . "
            ORDER BY c.id ASC LIMIT :fetch_limit";
    api_v1_responder_lista($pdo, $sql, $params, $pagination["limit"]);
}

if (preg_match('/\Acarregamentos\/([1-9][0-9]*)\z/', $route, $matches) === 1) {
    $client = api_v1_exigir_cliente(["carregamentos:read"]);
    $loadingId = api_v1_validar_id($matches[1]);
    $statement = $pdo->prepare(
        "SELECT c.id, c.state, c.romaneio_id, r.number AS romaneio_number,
                c.truck_id, t.plate, c.equipment_id, e.equipment_code, e.name AS equipment_name,
                c.started_at, c.finished_at, c.created_at, c.updated_at,
                COALESCE(c.leituras_validas, 0) AS valid_readings,
                COALESCE((SELECT SUM(ri.planned_quantity) FROM romaneio_itens ri
                          WHERE ri.romaneio_id = c.romaneio_id
                            AND (ri.truck_id = c.truck_id OR ri.truck_id IS NULL)), 0) AS planned_quantity
         FROM carregamentos c
         JOIN romaneios r ON r.id = c.romaneio_id AND r.company_id = c.company_id
         LEFT JOIN romaneio_caminhoes t ON t.id = c.truck_id AND t.romaneio_id = c.romaneio_id
         LEFT JOIN equipamentos e ON e.id = c.equipment_id AND e.company_id = c.company_id
         WHERE c.id = :id AND c.company_id = :company_id LIMIT 1",
    );
    $statement->execute(["id" => $loadingId, "company_id" => $client["company_id"]]);
    $loading = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($loading)) {
        api_v1_responder_erro("not_found", "Carregamento não encontrado.", 404);
    }
    header("Content-Type: application/json; charset=utf-8");
    responder_json(["data" => $loading]);
}

if (preg_match('/\Acarregamentos\/([1-9][0-9]*)\/leituras\z/', $route, $matches) === 1) {
    $client = api_v1_exigir_cliente(["leituras:read"]);
    $loadingId = api_v1_validar_id($matches[1]);
    $loadingCheck = $pdo->prepare(
        "SELECT id FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1",
    );
    $loadingCheck->execute(["id" => $loadingId, "company_id" => $client["company_id"]]);
    if (!$loadingCheck->fetchColumn()) {
        api_v1_responder_erro("not_found", "Carregamento não encontrado.", 404);
    }
    $pagination = api_v1_paginacao();
    $sql = "SELECT l.id, l.carregamento_id, l.sensor_event_id, l.product_id,
                   p.code AS product_code, p.name AS product_name, l.barcode, l.result,
                   l.read_at, l.created_at
            FROM leituras l
            JOIN carregamentos c ON c.id = l.carregamento_id
            LEFT JOIN produtos p ON p.id = l.product_id AND p.company_id = c.company_id
            WHERE c.company_id = :company_id AND c.id = :loading_id AND l.id > :cursor
            ORDER BY l.id ASC LIMIT :fetch_limit";
    api_v1_responder_lista($pdo, $sql, [
        "company_id" => $client["company_id"],
        "loading_id" => $loadingId,
        "cursor" => $pagination["cursor"],
    ], $pagination["limit"]);
}

if ($route === "equipamentos") {
    $client = api_v1_exigir_cliente(["equipamentos:read"]);
    $pagination = api_v1_paginacao();
    $staleSeconds = max(5, min(300, (int) limite_sinal_clp_segundos()));
    $sql = "SELECT e.id, e.equipment_code, e.name,
                   CASE
                     WHEN d.status = 'ONLINE'
                       AND d.last_seen_at >= DATE_SUB(NOW(3), INTERVAL {$staleSeconds} SECOND) THEN 'ONLINE'
                     WHEN d.status = 'ERRO' THEN 'ERRO'
                     ELSE 'OFFLINE'
                   END AS communication_status,
                   d.last_seen_at,
                   (SELECT c.state FROM carregamentos c
                    JOIN romaneios r ON r.id = c.romaneio_id AND r.company_id = c.company_id
                    WHERE c.company_id = e.company_id AND c.equipment_id = e.id
                      AND c.state <> 'FINALIZADO' AND r.status NOT IN ('FINALIZADO', 'CANCELADO')
                    ORDER BY c.id DESC LIMIT 1) AS active_loading_state
            FROM equipamentos e
            LEFT JOIN status_dispositivos d ON d.equipment_id = e.id AND d.device_type = 'CLP'
            WHERE e.company_id = :company_id AND e.id > :cursor
            ORDER BY e.id ASC LIMIT :fetch_limit";
    api_v1_responder_lista($pdo, $sql, [
        "company_id" => $client["company_id"],
        "cursor" => $pagination["cursor"],
    ], $pagination["limit"]);
}

if ($route === "ocorrencias") {
    $client = api_v1_exigir_cliente(["ocorrencias:read"]);
    $pagination = api_v1_paginacao();
    $sql = "SELECT o.id, o.type, o.quantity, o.description, o.carregamento_id,
                   o.product_id, p.name AS product_name, o.created_at
            FROM ocorrencias o
            LEFT JOIN produtos p ON p.id = o.product_id AND p.company_id = o.company_id
            WHERE o.company_id = :company_id AND o.id > :cursor
            ORDER BY o.id ASC LIMIT :fetch_limit";
    api_v1_responder_lista($pdo, $sql, [
        "company_id" => $client["company_id"],
        "cursor" => $pagination["cursor"],
    ], $pagination["limit"]);
}

api_v1_responder_erro("not_found", "Rota da API não encontrada.", 404);
