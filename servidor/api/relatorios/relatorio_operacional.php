<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    json_response(["error" => "Método não permitido."], 405);
}
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}

$parseDate = static function (string $value, string $label): ?string {
    $value = trim($value);
    if ($value === "") {
        return null;
    }
    $date = DateTime::createFromFormat("Y-m-d", $value);
    if (!$date || $date->format("Y-m-d") !== $value) {
        json_response(["error" => "{$label} inválida."], 422);
    }
    return $value;
};
$dateFrom = $parseDate((string) ($_GET["date_from"] ?? ""), "Data inicial");
$dateTo = $parseDate((string) ($_GET["date_to"] ?? ""), "Data final");
$status = strtoupper(trim((string) ($_GET["status"] ?? "")));
$allowedStatuses = ["AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];
if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    json_response(
        ["error" => "Data inicial não pode ser posterior à data final."],
        422,
    );
}
if ($status !== "" && !in_array($status, $allowedStatuses, true)) {
    json_response(["error" => "Status inválido."], 422);
}

$conditions = ["r.company_id = :company_id"];
$params = ["company_id" => $user["company_id"]];
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

session_write_close();
$pdo = db();
$statement = $pdo->prepare(
    "WITH quantidades_planejadas AS (
            SELECT romaneio_id, SUM(planned_quantity) AS planned_quantity
            FROM romaneio_itens
            GROUP BY romaneio_id
        ), leituras_por_resultado AS (
            SELECT c.romaneio_id,
                   SUM(l.result = 'VALIDO') AS loaded_quantity,
                   SUM(l.result = 'SEM_LEITURA') AS no_readings,
                   SUM(l.result = 'PRODUTO_INCORRETO') AS wrong_products
            FROM leituras l
            JOIN carregamentos c ON c.id = l.carregamento_id
            GROUP BY c.romaneio_id
        ), retornos_por_romaneio AS (
            SELECT c.romaneio_id, SUM(ret.quantity) AS returned_quantity
            FROM retornos ret
            JOIN carregamentos c ON c.id = ret.carregamento_id
            GROUP BY c.romaneio_id
        ), ocorrencias_por_romaneio AS (
            SELECT c.romaneio_id, COUNT(*) AS occurrences
            FROM ocorrencias o
            JOIN carregamentos c ON c.id = o.carregamento_id
            GROUP BY c.romaneio_id
        ), carregamento_recente AS (
            SELECT id, romaneio_id, state, started_at, finished_at,
                   ROW_NUMBER() OVER (PARTITION BY romaneio_id ORDER BY id DESC) AS rn
            FROM carregamentos
        )
     SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor,
            MAX(rt.plate) AS plate,
            COALESCE(qp.planned_quantity, 0) AS planned_quantity,
            GREATEST(0, COALESCE(lpr.loaded_quantity, 0) - COALESCE(rpr.returned_quantity, 0)) AS loaded_quantity,
            COALESCE(rpr.returned_quantity, 0) AS returned_quantity,
            COALESCE(lpr.no_readings, 0) AS no_readings,
            COALESCE(lpr.wrong_products, 0) AS wrong_products,
            COALESCE(opr.occurrences, 0) AS occurrences,
            c.state AS loading_state, c.started_at, c.finished_at,
            CASE WHEN c.started_at IS NOT NULL AND c.finished_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, c.started_at, c.finished_at) ELSE NULL END AS duration_minutes
     FROM romaneios r
     LEFT JOIN romaneio_caminhoes rt ON rt.romaneio_id = r.id
     LEFT JOIN quantidades_planejadas qp ON qp.romaneio_id = r.id
     LEFT JOIN leituras_por_resultado lpr ON lpr.romaneio_id = r.id
     LEFT JOIN retornos_por_romaneio rpr ON rpr.romaneio_id = r.id
     LEFT JOIN ocorrencias_por_romaneio opr ON opr.romaneio_id = r.id
     LEFT JOIN carregamento_recente c ON c.romaneio_id = r.id AND c.rn = 1
     WHERE " .
        implode(" AND ", $conditions) .
        "
     GROUP BY r.id, c.id, qp.planned_quantity, lpr.loaded_quantity, lpr.no_readings,
              lpr.wrong_products, rpr.returned_quantity, opr.occurrences
     ORDER BY r.scheduled_date DESC, r.id DESC",
);
$statement->execute($params);
$rows = $statement->fetchAll();

$summary = [
    "romaneios" => count($rows),
    "planejado" => 0,
    "carregado" => 0,
    "retornado" => 0,
    "ocorrencias" => 0,
    "sem_leitura" => 0,
    "produto_incorreto" => 0,
    "divergentes" => 0,
];
foreach ($rows as &$row) {
    foreach (
        [
            "planned_quantity",
            "loaded_quantity",
            "returned_quantity",
            "no_readings",
            "wrong_products",
            "occurrences",
        ]
        as $field
    ) {
        $row[$field] = (int) $row[$field];
    }
    $row["duration_minutes"] =
        $row["duration_minutes"] === null
            ? null
            : (int) $row["duration_minutes"];
    $row["has_divergence"] =
        $row["status"] === "FINALIZADO" &&
        ($row["planned_quantity"] !== $row["loaded_quantity"] ||
            $row["occurrences"] > 0)
            ? 1
            : 0;
    $summary["planejado"] += $row["planned_quantity"];
    $summary["carregado"] += $row["loaded_quantity"];
    $summary["retornado"] += $row["returned_quantity"];
    $summary["ocorrencias"] += $row["occurrences"];
    $summary["sem_leitura"] += $row["no_readings"];
    $summary["produto_incorreto"] += $row["wrong_products"];
    $summary["divergentes"] += $row["has_divergence"];
}
unset($row);
json_response([
    "data" => [
        "filters" => [
            "date_from" => $dateFrom,
            "date_to" => $dateTo,
            "status" => $status,
        ],
        "summary" => $summary,
        "rows" => $rows,
    ],
]);
