<?php

declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";

$usuarioAtor = exigir_sessao_usuario();
if ($usuarioAtor["company_id"] === null) {
    responder_json(["error" => "Usuário sem empresa vinculada."], 403);
}

$podeGerenciar = in_array(
    $usuarioAtor["role"],
    ["ADMIN_DALLOGIX", "ADMIN_EMPRESA", "SUPERVISOR"],
    true,
);
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    session_write_close();
}
$pdo = obter_conexao_banco();

$productResponse = static function (array $product): array {
    return [
        "id" => (int) $product["id"],
        "code" => $product["code"],
        "name" => $product["name"],
        "category" => $product["category"] ?? null,
        "active" => (int) $product["active"],
        "barcodes" => $product["barcodes"] ?? "",
    ];
};

$barcodeError = static function (PDOException $exception): never {
    if ((int) $exception->errorInfo[1] === 1062) {
        json_response(
            ["error" => "Código de barras já usado por outro produto."],
            409,
        );
    }
    throw $exception;
};

$syncBarcode = static function (PDO $pdo, int $companyId, int $productId, string $barcode) use (
    $barcodeError,
): void {
    $existing = $pdo->prepare(
        "SELECT id FROM codigos_produtos
         WHERE company_id = :company_id AND product_id = :product_id
         ORDER BY id LIMIT 1",
    );
    $existing->execute(["company_id" => $companyId, "product_id" => $productId]);
    $row = $existing->fetch();
    try {
        if ($row) {
            $update = $pdo->prepare(
                "UPDATE codigos_produtos SET barcode = :barcode
                 WHERE id = :id AND company_id = :company_id",
            );
            $update->execute([
                "barcode" => $barcode,
                "id" => $row["id"],
                "company_id" => $companyId,
            ]);
            return;
        }
        $insert = $pdo->prepare(
            "INSERT INTO codigos_produtos (company_id, product_id, barcode) VALUES (:company_id, :product_id, :barcode)",
        );
        $insert->execute(["company_id" => $companyId, "product_id" => $productId, "barcode" => $barcode]);
    } catch (PDOException $exception) {
        $barcodeError($exception);
    }
};

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $pagination = parametros_paginacao_lista($_GET);
    $search = trim((string) ($_GET["search"] ?? ""));
    $conditions = ["p.company_id = :company_id"];
    $params = [
        "company_id" => (int) $usuarioAtor["company_id"],
        "cursor" => $pagination["cursor"],
        "fetch_limit" => $pagination["limit"] + 1,
    ];
    $conditions[] = "p.id > :cursor";
    if ($search !== "") {
        $conditions[] =
            "(p.name LIKE :search OR p.code LIKE :search OR p.category LIKE :search OR EXISTS (
                SELECT 1 FROM codigos_produtos pc
                WHERE pc.company_id = p.company_id AND pc.product_id = p.id AND pc.barcode LIKE :search
            ))";
        $params["search"] = "%" . addcslashes($search, "%_\\") . "%";
    }
    $statement = $pdo->prepare(
        'SELECT p.id, p.code, p.name, p.category, p.active, GROUP_CONCAT(pc.barcode ORDER BY pc.barcode SEPARATOR ",") AS barcodes
         FROM produtos p LEFT JOIN codigos_produtos pc
           ON pc.company_id = p.company_id AND pc.product_id = p.id
         WHERE ' .
            implode(" AND ", $conditions) .
            '
             GROUP BY p.id ORDER BY p.id LIMIT :fetch_limit',
    );
    foreach ($params as $name => $value) {
        $statement->bindValue(
            ":{$name}",
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR,
        );
    }
    $statement->execute();
    $page = pagina_lista_por_id(
        array_map($productResponse, $statement->fetchAll(PDO::FETCH_ASSOC)),
        $pagination["limit"],
    );
    json_response($page);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_csrf();
    if (!$podeGerenciar) {
        json_response(
            ["error" => "Perfil sem permissão para cadastrar produto."],
            403,
        );
    }
    $payload = request_json();
    $name = trim((string) ($payload["name"] ?? ""));
    $barcode = trim(
        (string) ($payload["barcode"] ?? ($payload["barcodes"] ?? "")),
    );
    $code = trim((string) ($payload["code"] ?? ($payload["sku"] ?? "")));
    $category = trim((string) ($payload["category"] ?? ""));
    if ($name === "" || $barcode === "") {
        json_response(
            ["error" => "Nome e código de barras são obrigatórios."],
            422,
        );
    }
    try {
        $pdo->beginTransaction();
        // SKU opcional: quando vazio, gera um código único a partir do id.
        $temporaryCode =
            $code !== "" ? $code : "TMP-" . bin2hex(random_bytes(8));
        $insert = $pdo->prepare(
            "INSERT INTO produtos (company_id, code, name, category) VALUES (:company_id, :code, :name, :category)",
        );
        $insert->execute([
            "company_id" => $usuarioAtor["company_id"],
            "code" => $temporaryCode,
            "name" => $name,
            "category" => $category !== "" ? $category : null,
        ]);
        $productId = (int) $pdo->lastInsertId();
        if ($code === "") {
            $finalize = $pdo->prepare(
                "UPDATE produtos SET code = :code WHERE id = :id",
            );
            $finalize->execute([
                "code" => "SKU" . $productId,
                "id" => $productId,
            ]);
            $code = "SKU" . $productId;
        }
        $syncBarcode($pdo, (int) $usuarioAtor["company_id"], $productId, $barcode);
        record_operational_event(
            $pdo,
            $usuarioAtor,
            "PRODUTO_CADASTRADO",
            "produto",
            $productId,
            ["code" => $code, "barcode" => $barcode],
        );
        $pdo->commit();
        json_response(
            [
                "data" => [
                    "id" => $productId,
                    "code" => $code,
                    "name" => $name,
                    "category" => $category !== "" ? $category : null,
                    "active" => 1,
                    "barcodes" => $barcode,
                ],
            ],
            201,
        );
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) $exception->errorInfo[1] === 1062) {
            json_response(
                ["error" => "Produto ou código de barras já cadastrado."],
                409,
            );
        }
        json_response(["error" => "Não foi possível salvar o produto."], 500);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "PUT") {
    require_csrf();
    if (!$podeGerenciar) {
        json_response(
            ["error" => "Perfil sem permissão para editar produto."],
            403,
        );
    }
    $payload = request_json();
    $productId = filter_var($payload["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$productId) {
        json_response(["error" => "Produto não informado."], 422);
    }
    $find = $pdo->prepare(
        "SELECT id FROM produtos WHERE id = :id AND company_id = :company_id LIMIT 1",
    );
    $find->execute(["id" => $productId, "company_id" => $usuarioAtor["company_id"]]);
    if (!$find->fetch()) {
        json_response(["error" => "Produto não encontrado."], 404);
    }
    $name = trim((string) ($payload["name"] ?? ""));
    $code = trim((string) ($payload["code"] ?? ($payload["sku"] ?? "")));
    $category = trim((string) ($payload["category"] ?? ""));
    $active = array_key_exists("active", $payload)
        ? (int) (bool) $payload["active"]
        : null;
    if ($name === "") {
        json_response(["error" => "Nome do produto é obrigatório."], 422);
    }
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            "UPDATE produtos SET name = :name, code = COALESCE(NULLIF(:code, ''), code), category = :category, active = COALESCE(:active, active) WHERE id = :id",
        );
        $update->execute([
            "name" => $name,
            "code" => $code,
            "category" => $category !== "" ? $category : null,
            "active" => $active,
            "id" => $productId,
        ]);
        $barcode = trim(
            (string) ($payload["barcode"] ?? ($payload["barcodes"] ?? "")),
        );
        if ($barcode !== "") {
            $syncBarcode($pdo, (int) $usuarioAtor["company_id"], $productId, $barcode);
        }
        record_operational_event(
            $pdo,
            $usuarioAtor,
            "PRODUTO_ATUALIZADO",
            "produto",
            $productId,
            ["name" => $name],
        );
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            json_response(
                [
                    "error" =>
                    "SKU ou código de barras já usado por outro produto.",
                ],
                409,
            );
        }
        json_response(
            ["error" => "Não foi possível atualizar o produto."],
            500,
        );
    }
    json_response(["data" => ["updated" => true]]);
}

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    require_csrf();
    if (!$podeGerenciar) {
        json_response(
            ["error" => "Perfil sem permissão para excluir produto."],
            403,
        );
    }
    $productId = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
    if (!$productId) {
        json_response(["error" => "Produto não informado."], 422);
    }
    $find = $pdo->prepare(
        "SELECT p.id, p.code, p.name, p.category, p.active, p.remote_product_id,
                COALESCE((SELECT cp.barcode FROM codigos_produtos cp
                          WHERE cp.company_id = p.company_id AND cp.product_id = p.id
                          ORDER BY cp.id LIMIT 1), '') AS barcode
         FROM produtos p
         WHERE p.id = :id AND p.company_id = :company_id LIMIT 1",
    );
    $find->execute(["id" => $productId, "company_id" => $usuarioAtor["company_id"]]);
    $productBeforeDelete = $find->fetch();
    if (!$productBeforeDelete) {
        json_response(["error" => "Produto não encontrado."], 404);
    }
    try {
        $pdo->beginTransaction();
        // Remove referências de catálogo e os itens planejados do produto.
        // Leituras e ocorrências permanecem preservadas, sem apontar para um
        // cadastro que deixou de existir.
        $pdo->prepare(
            "DELETE FROM codigos_produtos WHERE company_id = :company_id AND product_id = :id",
        )->execute(["company_id" => $usuarioAtor["company_id"], "id" => $productId]);
        $pdo->prepare(
            "UPDATE leituras SET product_id = NULL WHERE product_id = :id",
        )->execute(["id" => $productId]);
        $pdo->prepare(
            "UPDATE ocorrencias SET product_id = NULL WHERE product_id = :id",
        )->execute(["id" => $productId]);
        $pdo->prepare(
            "DELETE FROM romaneio_itens WHERE product_id = :id",
        )->execute(["id" => $productId]);
        // Eventos locais antigos do mesmo produto não podem reaparecer depois
        // da exclusão. O evento de exclusão é gravado logo abaixo.
        $pdo->prepare(
            "DELETE FROM fila_sincronizacao
             WHERE company_id = :company_id AND aggregate_type = 'produto'
               AND aggregate_id = :id AND status IN ('PENDENTE', 'ERRO')",
        )->execute([
            "company_id" => $usuarioAtor["company_id"],
            "id" => $productId,
        ]);
        $pdo->prepare("DELETE FROM produtos WHERE id = :id")->execute([
            "id" => $productId,
        ]);
        record_operational_event(
            $pdo,
            $usuarioAtor,
            "PRODUTO_EXCLUIDO",
            "produto",
            $productId,
            [
                "remote_product_id" => $productBeforeDelete["remote_product_id"] === null
                    ? $productId
                    : (int) $productBeforeDelete["remote_product_id"],
                "code" => $productBeforeDelete["code"],
                "name" => $productBeforeDelete["name"],
                "category" => $productBeforeDelete["category"],
                "active" => (int) $productBeforeDelete["active"],
                "barcode" => $productBeforeDelete["barcode"],
            ],
        );
        $pdo->commit();
        json_response(["data" => ["deleted" => true]]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(["error" => "Não foi possível excluir o produto."], 500);
    }
}

json_response(["error" => "Método não permitido."], 405);
