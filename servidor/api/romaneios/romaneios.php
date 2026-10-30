<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioRomaneiosLista.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioItensRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioProdutosRomaneio.php";

use App\Persistencia\RepositorioRomaneiosLista;
use App\Persistencia\RepositorioItensRomaneio;
use App\Persistencia\RepositorioProdutosRomaneio;

exigir_metodo_http(["GET", "PATCH", "POST"]);

$usuarioAtor = exigir_sessao_usuario();
$empresaId = $usuarioAtor["company_id"];
if ($empresaId === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}

$companyId = (int) $empresaId;
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = obter_conexao_banco();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $detailId = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if ($detailId) {
        $header = $pdo->prepare(
            "SELECT r.id, r.number, r.scheduled_date, r.status, r.expedidor, r.created_at, r.updated_at,
                    (SELECT rt.plate FROM romaneio_caminhoes rt WHERE rt.romaneio_id = r.id ORDER BY rt.id LIMIT 1) AS plate,
                    (SELECT rt.driver_name FROM romaneio_caminhoes rt WHERE rt.romaneio_id = r.id ORDER BY rt.id LIMIT 1) AS driver_name,
                    COALESCE((SELECT SUM(ri.planned_quantity) FROM romaneio_itens ri WHERE ri.romaneio_id = r.id), 0) AS planned_quantity,
                    COALESCE((SELECT SUM(ret.quantity) FROM retornos ret
                              JOIN carregamentos c3 ON c3.id = ret.carregamento_id
                              WHERE c3.romaneio_id = r.id AND c3.company_id = r.company_id), 0) AS returned_quantity,
                    GREATEST(0,
                      COALESCE((SELECT COUNT(*) FROM leituras l JOIN carregamentos c2 ON c2.id = l.carregamento_id WHERE c2.romaneio_id = r.id AND l.result = 'VALIDO'), 0)
                      - COALESCE((SELECT SUM(ret.quantity) FROM retornos ret JOIN carregamentos c4 ON c4.id = ret.carregamento_id WHERE c4.romaneio_id = r.id AND c4.company_id = r.company_id), 0)
                    ) AS loaded_quantity,
                    COALESCE((SELECT COUNT(*) FROM eventos_sensor s JOIN carregamentos c5 ON c5.id = s.carregamento_id WHERE c5.romaneio_id = r.id AND c5.company_id = r.company_id), 0) AS detected_quantity,
                    (SELECT COUNT(*) FROM carregamentos c6 WHERE c6.romaneio_id = r.id AND c6.company_id = r.company_id) AS loading_count,
                    (SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i JOIN carregamentos c7 ON c7.id = i.carregamento_id WHERE c7.romaneio_id = r.id AND c7.company_id = r.company_id AND i.status IN ('PENDENTE','CAPTURANDO')) AS pending_evidence_count,
                    (SELECT c8.id FROM carregamentos c8 WHERE c8.romaneio_id = r.id AND c8.company_id = r.company_id AND c8.state <> 'FINALIZADO' AND r.status NOT IN ('FINALIZADO', 'CANCELADO') ORDER BY c8.id DESC LIMIT 1) AS active_loading_id
             FROM romaneios r WHERE r.id = :id AND r.company_id = :company_id LIMIT 1",
        );
        $header->execute(["id" => $detailId, "company_id" => $companyId]);
        $romaneio = $header->fetch();
        if (!$romaneio) {
            responder_json(["error" => "Romaneio não encontrado."], 404);
        }

        $items = $pdo->prepare(
            'SELECT ri.id, ri.product_id, ri.truck_id, ri.planned_quantity, p.code, p.name, p.category,
                    COALESCE((SELECT pc.barcode FROM codigos_produtos pc
                              WHERE pc.company_id = p.company_id AND pc.product_id = p.id
                              ORDER BY pc.id LIMIT 1), "") AS barcode
             FROM romaneio_itens ri JOIN produtos p ON p.id = ri.product_id
             WHERE ri.romaneio_id = :id ORDER BY ri.id',
        );
        $items->execute(["id" => $detailId]);
        $romaneio["items"] = $items->fetchAll();

        $trucks = $pdo->prepare(
            "SELECT id, plate, driver_name FROM romaneio_caminhoes WHERE romaneio_id = :id ORDER BY id",
        );
        $trucks->execute(["id" => $detailId]);
        $romaneio["trucks"] = $trucks->fetchAll();
        responder_json(["data" => $romaneio]);
    }

    $parseDate = static function (string $value, string $label): ?string {
        $value = trim($value);
        if ($value === "") {
            return null;
        }
        $date = DateTime::createFromFormat("Y-m-d", $value);
        if (!$date || $date->format("Y-m-d") !== $value) {
            responder_json(["error" => "{$label} inválida."], 422);
        }
        return $value;
    };

    $dateFrom = $parseDate((string) ($_GET["date_from"] ?? ""), "Data inicial");
    $dateTo = $parseDate((string) ($_GET["date_to"] ?? ""), "Data final");
    $number = trim((string) ($_GET["number"] ?? ""));
    $expedidor = trim((string) ($_GET["expedidor"] ?? ""));
    $status = strtoupper(trim((string) ($_GET["status"] ?? "")));
    $page = filter_var(
        $_GET["page"] ?? 1,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1, "max_range" => 1000000]],
    );
    $perPage = filter_var(
        $_GET["per_page"] ?? 50,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 10, "max_range" => 100]],
    );
    if ($page === false || $perPage === false) {
        responder_json(["error" => "Paginação inválida."], 422);
    }
    $offset = ($page - 1) * $perPage;
    $allowedStatuses = ["IMPORTADO", "AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];

    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        responder_json(["error" => "Data inicial não pode ser posterior à data final."], 422);
    }
    if ($status !== "" && !in_array($status, $allowedStatuses, true)) {
        responder_json(["error" => "Status inválido."], 422);
    }

    if ($number !== "") {
        if (mb_strlen($number, "UTF-8") > 80) {
            responder_json(["error" => "O número pode ter no máximo 80 caracteres."], 422);
        }
    }
    if ($expedidor !== "") {
        if (mb_strlen($expedidor, "UTF-8") > 160) {
            responder_json(["error" => "O expedidor pode ter no máximo 160 caracteres."], 422);
        }
    }

    $filters = [
        "date_from" => $dateFrom,
        "date_to" => $dateTo,
        "number" => $number,
        "expedidor" => $expedidor,
        "status" => $status !== "" ? $status : null,
    ];
    $repository = new RepositorioRomaneiosLista($pdo);
    $total = $repository->contar($companyId, $filters);
    $rows = $repository->listar($companyId, $filters, $perPage, $offset);

    foreach ($rows as &$row) {
        $finalizado = $row["status"] === "FINALIZADO";
        $row["has_divergence"] =
            $finalizado && (
                ((int) $row["loaded_quantity"] !== (int) $row["planned_quantity"]) ||
                (int) $row["ocorrencias_count"] > 0
            )
            ? 1
            : 0;
    }
    unset($row);
    responder_json([
        "data" => $rows,
        "meta" => [
            "page" => $page,
            "per_page" => $perPage,
            "total" => $total,
            "pages" => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ],
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "PATCH") {
    exigir_csrf();
    $payload = ler_json_da_requisicao();
    $action = strtolower(trim((string) ($payload["action"] ?? "")));
    if (!in_array($action, ["cancel", "finish", "update"], true)) {
        responder_json(
            ["error" => "Ação inválida. Use cancel, finish ou update."],
            422,
        );
    }
    $allowedRoles = $action === "finish"
        ? ["ADMIN_EMPRESA", "SUPERVISOR", "USUARIO"]
        : ["ADMIN_EMPRESA", "SUPERVISOR"];
    if (!in_array($usuarioAtor["role"], $allowedRoles, true)) {
        responder_json(["error" => "Seu perfil não pode executar esta ação no romaneio."], 403);
    }

    if ($action === "finish") {
        $romaneioId = filter_var($payload["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$romaneioId) {
            responder_json(["error" => "Romaneio obrigatório."], 422);
        }
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                "SELECT id, number, status FROM romaneios
                 WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE",
            );
            $lock->execute(["id" => $romaneioId, "company_id" => $companyId]);
            $current = $lock->fetch();
            if (!$current) {
                $pdo->rollBack();
                responder_json(["error" => "Romaneio não encontrado."], 404);
            }
            if ($current["status"] === "FINALIZADO") {
                $pdo->rollBack();
                responder_json(["data" => ["id" => (int) $romaneioId, "status" => "FINALIZADO", "duplicate" => true]]);
            }
            if ($current["status"] !== "EM_ANDAMENTO") {
                $pdo->rollBack();
                responder_json(["error" => "Somente um romaneio em andamento pode ser encerrado."], 409);
            }

            $loadings = $pdo->prepare(
                "SELECT id, state FROM carregamentos
                 WHERE romaneio_id = :romaneio_id AND company_id = :company_id
                 ORDER BY id FOR UPDATE",
            );
            $loadings->execute(["romaneio_id" => $romaneioId, "company_id" => $companyId]);
            $loadingRows = $loadings->fetchAll();
            if ($loadingRows === []) {
                $pdo->rollBack();
                responder_json(["error" => "O romaneio ainda não possui carregamentos finalizados."], 409);
            }
            foreach ($loadingRows as $loadingRow) {
                if ($loadingRow["state"] !== "FINALIZADO") {
                    $pdo->rollBack();
                    responder_json(["error" => "Finalize todos os carregamentos antes de encerrar o romaneio."], 409);
                }
            }
            $pendingEvidence = $pdo->prepare(
                "SELECT COUNT(*) FROM solicitacoes_imagens_evidencia i
                 JOIN carregamentos c ON c.id = i.carregamento_id
                 WHERE c.romaneio_id = :romaneio_id AND c.company_id = :company_id
                   AND i.status IN ('PENDENTE', 'CAPTURANDO')",
            );
            $pendingEvidence->execute(["romaneio_id" => $romaneioId, "company_id" => $companyId]);
            if ((int) $pendingEvidence->fetchColumn() > 0) {
                $pdo->rollBack();
                responder_json(["error" => "Aguarde o recebimento das imagens de falha antes de encerrar o romaneio."], 409);
            }

            $finish = $pdo->prepare(
                "UPDATE romaneios SET status = 'FINALIZADO'
                 WHERE id = :id AND company_id = :company_id AND status = 'EM_ANDAMENTO'",
            );
            $finish->execute(["id" => $romaneioId, "company_id" => $companyId]);
            if ($finish->rowCount() !== 1) {
                throw new RuntimeException("O romaneio mudou de estado durante o encerramento.");
            }
            record_operational_event(
                $pdo,
                $usuarioAtor,
                "ROMANEIO_FINALIZADO",
                "romaneio",
                (int) $romaneioId,
                ["loading_count" => count($loadingRows), "number" => (string) $current["number"]],
            );
            $pdo->commit();
            responder_json(["data" => ["id" => (int) $romaneioId, "status" => "FINALIZADO"]]);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Manifest finish failed: " . $exception->getMessage());
            responder_json(["error" => "Não foi possível encerrar o romaneio."], 500);
        }
    }

    if ($action === "cancel") {
        $romaneioId = filter_var($payload["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($payload["justification"] ?? ""));
        if (!$romaneioId || $reason === "") {
            responder_json(["error" => "Romaneio e justificativa são obrigatórios para cancelar uma operação."], 422);
        }
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT r.id, r.status FROM romaneios r WHERE r.id = :id AND r.company_id = :company_id LIMIT 1 FOR UPDATE");
            $lock->execute(["id" => $romaneioId, "company_id" => $companyId]);
            $current = $lock->fetch();
            if (!$current) { $pdo->rollBack(); responder_json(["error" => "Romaneio não encontrado."], 404); }
            if (in_array($current["status"], ["FINALIZADO", "CANCELADO"], true)) { $pdo->rollBack(); responder_json(["error" => "Este romaneio já foi encerrado."], 409); }
            $activeLoadingsStatement = $pdo->prepare("SELECT id, state FROM carregamentos WHERE romaneio_id = :romaneio_id AND company_id = :company_id AND state <> 'FINALIZADO' ORDER BY id DESC FOR UPDATE");
            $activeLoadingsStatement->execute(["romaneio_id" => $romaneioId, "company_id" => $companyId]);
            $activeLoadings = $activeLoadingsStatement->fetchAll();
            foreach ($activeLoadings as $activeLoading) {
                if (!in_array($activeLoading["state"], ["PREPARANDO", "PAUSADO", "EMERGENCIA"], true)) {
                    $pdo->rollBack(); responder_json(["error" => "Antes de cancelar, coloque todas as Dalas em pausa ou emergência."], 409);
                }
            }
            if ($activeLoadings !== []) {
                $updateLoading = $pdo->prepare("UPDATE carregamentos SET state = 'FINALIZADO', finished_at = NOW(), finish_justification = :reason WHERE romaneio_id = :romaneio_id AND company_id = :company_id AND state <> 'FINALIZADO'");
                $updateLoading->execute(["romaneio_id" => $romaneioId, "company_id" => $companyId, "reason" => "ROMANEIO CANCELADO: " . $reason]);
                foreach ($activeLoadings as $activeLoading) {
                    record_operational_event($pdo, $usuarioAtor, "CARREGAMENTO_CANCELADO", "carregamento", (int) $activeLoading["id"], ["romaneio_id" => (int) $romaneioId, "justification" => $reason]);
                }
            }
            $pdo->prepare("UPDATE romaneios SET status = 'CANCELADO' WHERE id = :id AND company_id = :company_id")->execute(["id" => $romaneioId, "company_id" => $companyId]);
            record_operational_event($pdo, $usuarioAtor, "ROMANEIO_CANCELADO", "romaneio", (int) $romaneioId, ["justification" => $reason, "in_progress" => $activeLoadings !== []]);
            $pdo->commit();
            responder_json(["data" => ["id" => (int) $romaneioId, "status" => "CANCELADO"]]);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Manifest cancellation failed: " . $exception->getMessage());
            responder_json(["error" => "Não foi possível cancelar o romaneio."], 500);
        }
    }

    // O ramo cancel retorna via responder_json(); portanto, neste ponto a
    // validação anterior garante que a única ação restante é update.
        $romaneioId = filter_var($payload["romaneio_id"] ?? null, FILTER_VALIDATE_INT);
        $number = trim((string) ($payload["number"] ?? ""));
        $scheduledDate = trim((string) ($payload["scheduled_date"] ?? ""));
        $plate = strtoupper(trim((string) ($payload["plate"] ?? "")));
        $driverName = trim((string) ($payload["driver_name"] ?? ""));
        $expedidor = trim((string) ($payload["expedidor"] ?? ""));

        if (!$romaneioId || $number === "" || !placa_caminhao_valida($plate) || mb_strlen($number) > 80 || !preg_match('/^[A-Za-z0-9À-ÿ _.,\/-]{1,80}$/u', $number)) {
            responder_json(["error" => "Romaneio, código e placa são obrigatórios."], 422);
        }

        $date = DateTime::createFromFormat("Y-m-d", $scheduledDate);
        if (!$date || $date->format("Y-m-d") !== $scheduledDate) {
            responder_json(["error" => "Data do carregamento inválida."], 422);
        }
        if ($scheduledDate < date("Y-m-d")) {
            responder_json(["error" => "A data do carregamento não pode ser anterior ao dia atual do PC industrial."], 422);
        }

        $items = $payload["items"] ?? [];
        if (!is_array($items) || $items === [] || count($items) > 100) {
            responder_json(["error" => "Informe entre 1 e 100 itens com produto e quantidade."], 422);
        }

        $normalizedItems = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                responder_json(["error" => "Item " . ($index + 1) . " inválido."], 422);
            }
            $productId = filter_var($item["product_id"] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item["quantity"] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || $quantity === false || $quantity < 1) {
                responder_json(["error" => "Item " . ($index + 1) . " inválido."], 422);
            }
            $normalizedItems[] = ["product_id" => $productId, "quantity" => $quantity];
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                "SELECT r.id, r.status, EXISTS(SELECT 1 FROM carregamentos c WHERE c.romaneio_id = r.id) AS has_loading
                 FROM romaneios r WHERE r.id = :id AND r.company_id = :company_id LIMIT 1 FOR UPDATE",
            );
            $statement->execute(["id" => $romaneioId, "company_id" => $companyId]);
            $romaneio = $statement->fetch();
            if (!$romaneio) {
                $pdo->rollBack();
                responder_json(["error" => "Romaneio não encontrado."], 404);
            }
            if (!in_array($romaneio["status"], ["IMPORTADO", "AGUARDANDO"], true) || (int) $romaneio["has_loading"] === 1) {
                $pdo->rollBack();
                responder_json(["error" => "Só é possível editar um romaneio antes de iniciar o carregamento."], 409);
            }

            $productIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item["product_id"],
                $normalizedItems,
            )));
            $activeProducts = (new RepositorioProdutosRomaneio($pdo))
                ->buscarAtivosPorIds($companyId, $productIds);
            if (count($activeProducts) !== count($productIds)) {
                $pdo->rollBack();
                responder_json(["error" => "Produto não encontrado ou inativo."], 422);
            }

            $truckStatement = $pdo->prepare(
                "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id ORDER BY id LIMIT 1 FOR UPDATE",
            );
            $truckStatement->execute(["romaneio_id" => $romaneioId]);
            $truck = $truckStatement->fetch();
            if (!$truck) {
                $pdo->rollBack();
                responder_json(["error" => "Caminhão do romaneio não encontrado."], 409);
            }

            $pdo->prepare(
                "UPDATE romaneios SET number = :number, scheduled_date = :scheduled_date, expedidor = :expedidor WHERE id = :id AND company_id = :company_id",
            )->execute([
                "number" => $number,
                "scheduled_date" => $scheduledDate,
                "expedidor" => $expedidor !== "" ? $expedidor : null,
                "id" => $romaneioId,
                "company_id" => $companyId,
            ]);

            $pdo->prepare(
                "UPDATE romaneio_caminhoes SET plate = :plate, driver_name = :driver_name WHERE id = :id",
            )->execute([
                "plate" => $plate,
                "driver_name" => $driverName !== "" ? $driverName : null,
                "id" => $truck["id"],
            ]);

            $pdo->prepare("DELETE FROM romaneio_itens WHERE romaneio_id = :romaneio_id")
                ->execute(["romaneio_id" => $romaneioId]);

            (new RepositorioItensRomaneio($pdo))->inserirLinhas(
                $companyId,
                array_map(
                    static fn (array $item): array => [
                        "romaneio_id" => (int) $romaneioId,
                        "product_id" => (int) $item["product_id"],
                        "truck_id" => (int) $truck["id"],
                        "quantity" => (int) $item["quantity"],
                    ],
                    $normalizedItems,
                ),
            );

            record_operational_event(
                $pdo,
                $usuarioAtor,
                "ROMANEIO_ATUALIZADO",
                "romaneio",
                (int) $romaneioId,
                ["items" => count($normalizedItems)],
            );
            $pdo->commit();
            responder_json(["data" => ["id" => (int) $romaneioId, "status" => $romaneio["status"]]]);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) $exception->errorInfo[1] === 1062) {
                responder_json(["error" => "Já existe um romaneio com este número."], 409);
            }
            responder_json(["error" => "Não foi possível atualizar o romaneio."], 500);
        }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responder_json(["error" => "Método não permitido."], 405);
}

exigir_csrf();
if (!in_array($usuarioAtor["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    responder_json(["error" => "Perfil sem permissão para cadastrar romaneio."], 403);
}

$payload = ler_json_da_requisicao();
$number = trim((string) ($payload["number"] ?? ""));
$scheduledDate = trim((string) ($payload["scheduled_date"] ?? date("Y-m-d")));
$plate = strtoupper(trim((string) ($payload["plate"] ?? "")));
$driverName = trim((string) ($payload["driver_name"] ?? ""));
$expedidor = trim((string) ($payload["expedidor"] ?? ""));

if ($number === "" || !placa_caminhao_valida($plate) || mb_strlen($number) > 80 || !preg_match('/^[A-Za-z0-9À-ÿ _.,\/-]{1,80}$/u', $number)) {
    responder_json(["error" => "Número e placa do caminhão são obrigatórios."], 422);
}

$date = DateTime::createFromFormat("Y-m-d", $scheduledDate);
if (!$date || $date->format("Y-m-d") !== $scheduledDate) {
    responder_json(["error" => "Data do carregamento inválida."], 422);
}
if ($scheduledDate < date("Y-m-d")) {
    responder_json(["error" => "A data do carregamento não pode ser anterior ao dia atual do PC industrial."], 422);
}

$items = $payload["items"] ?? null;
if (!is_array($items) || $items === []) {
    $legacyCode = trim((string) ($payload["product_code"] ?? ""));
    $legacyQuantity = filter_var($payload["planned_quantity"] ?? null, FILTER_VALIDATE_INT);
    if ($legacyCode !== "" && $legacyQuantity !== false && $legacyQuantity >= 1) {
        $items = [["product_code" => $legacyCode, "quantity" => $legacyQuantity]];
    }
}
if (!is_array($items) || $items === []) {
    responder_json(["error" => "Informe ao menos um item com produto e quantidade."], 422);
}
if (count($items) > 100) {
    responder_json(["error" => "Um romaneio pode conter no máximo 100 itens."], 422);
}

$normalizedItems = [];
foreach (array_values($items) as $index => $item) {
    if (!is_array($item)) {
        responder_json(["error" => "Item " . ($index + 1) . " inválido."], 422);
    }

    $quantity = filter_var($item["quantity"] ?? ($item["planned_quantity"] ?? null), FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 1) {
        responder_json(["error" => "Item " . ($index + 1) . ": quantidade inválida."], 422);
    }

    $productId = filter_var($item["product_id"] ?? null, FILTER_VALIDATE_INT);
    $rawCode = trim((string) ($item["product_code"] ?? ($item["code"] ?? ($item["barcode"] ?? ""))));
    if (!$productId && $rawCode === "") {
        responder_json(["error" => "Item " . ($index + 1) . ": produto não informado."], 422);
    }

    $productKey = $productId ? "id:" . $productId : "code:" . $rawCode;
    $normalizedItems[] = [
        "product_id" => $productId,
        "key" => $productKey,
        "quantity" => $quantity,
    ];
}

try {
    $pdo->beginTransaction();

    $productRepository = new RepositorioProdutosRomaneio($pdo);
    $productIds = [];
    $productIdentifiers = [];
    foreach ($normalizedItems as $item) {
        if ($item["product_id"]) {
            $productIds[] = (int) $item["product_id"];
        } else {
            $productIdentifiers[] = substr((string) $item["key"], 5);
        }
    }
    $productsById = $productRepository->buscarAtivosPorIds($companyId, $productIds);
    $productsByIdentifier = $productRepository->buscarAtivosPorCodigoOuBarras(
        $companyId,
        $productIdentifiers,
    );
    $resolvedItems = [];
    foreach ($normalizedItems as $item) {
        $identifier = substr((string) $item["key"], 5);
        $product = $item["product_id"]
            ? ($productsById[(int) $item["product_id"]] ?? null)
            : ($productsByIdentifier[mb_strtolower($identifier, "UTF-8")] ?? null);
        if (!$product) {
            $pdo->rollBack();
            responder_json(
                ["error" => "Produto não encontrado ou inativo: " . substr((string) $item["key"], 5)],
                422,
            );
        }

        $resolvedItems[] = [
            "product" => $product,
            "quantity" => $item["quantity"],
        ];
    }

    $romaneioStatement = $pdo->prepare(
        'INSERT INTO romaneios (company_id, number, scheduled_date, status, expedidor) VALUES (:company_id, :number, :scheduled_date, \'AGUARDANDO\', :expedidor)',
    );
    $romaneioStatement->execute([
        "company_id" => $companyId,
        "number" => $number,
        "scheduled_date" => $scheduledDate,
        "expedidor" => $expedidor !== "" ? $expedidor : null,
    ]);
    $romaneioId = (int) $pdo->lastInsertId();

    $truckStatement = $pdo->prepare(
        "INSERT INTO romaneio_caminhoes (romaneio_id, plate, driver_name) VALUES (:romaneio_id, :plate, :driver_name)",
    );
    $truckStatement->execute([
        "romaneio_id" => $romaneioId,
        "plate" => $plate,
        "driver_name" => $driverName !== "" ? $driverName : null,
    ]);
    $truckId = (int) $pdo->lastInsertId();

    (new RepositorioItensRomaneio($pdo))->inserirLinhas(
        $companyId,
        array_map(
            static fn (array $entry): array => [
                "romaneio_id" => $romaneioId,
                "product_id" => (int) $entry["product"]["id"],
                "truck_id" => $truckId,
                "quantity" => (int) $entry["quantity"],
            ],
            $resolvedItems,
        ),
    );

    record_operational_event(
        $pdo,
        $usuarioAtor,
        "ROMANEIO_CRIADO",
        "romaneio",
        $romaneioId,
        [
            "number" => $number,
            "truck_id" => $truckId,
                "items" => count($resolvedItems),
        ],
    );
    $pdo->commit();

    responder_json(
        [
            "data" => [
                "id" => $romaneioId,
                "number" => $number,
                "truck_id" => $truckId,
                "items" => count($resolvedItems),
                "status" => "AGUARDANDO",
            ],
        ],
        201,
    );
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((int) $exception->errorInfo[1] === 1062) {
        responder_json(["error" => "Já existe um romaneio com este número."], 409);
    }
    responder_json(["error" => "Não foi possível salvar o romaneio."], 500);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
