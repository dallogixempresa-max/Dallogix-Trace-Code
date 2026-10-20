<?php
declare(strict_types=1);

require_once __DIR__ . "/../../src/Persistencia/RepositorioItensRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioProdutosRomaneio.php";

use App\Persistencia\RepositorioItensRomaneio;
use App\Persistencia\RepositorioProdutosRomaneio;

final class ApiV1PayloadException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = "invalid_payload")
    {
        parent::__construct($message);
    }
}

function api_v1_chave_idempotencia(?string $value): string
{
    if ($value === null || $value === "") {
        throw new ApiV1PayloadException(
            "Envie uma Idempotency-Key única para proteger a criação contra reenvio duplicado.",
            "idempotency_key_required",
        );
    }
    if (preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $value) !== 1) {
        throw new ApiV1PayloadException(
            "Idempotency-Key deve ter entre 16 e 128 caracteres seguros.",
            "invalid_idempotency_key",
        );
    }
    return $value;
}

/** @return array{equipment_code:string,number:string,scheduled_date:string,expedidor:?string,plate:string,driver_name:?string,items:list<array{product_id:?int,product_code:?string,quantity:int}>} */
function api_v1_normalizar_payload_romaneio(array $payload, bool $permitirDataPassada = false): array
{
    $allowedFields = ["equipment_code", "number", "scheduled_date", "expedidor", "plate", "driver_name", "items"];
    if (array_diff(array_keys($payload), $allowedFields) !== []) {
        throw new ApiV1PayloadException("O corpo contém campos não permitidos. A empresa é definida pela chave da API.");
    }

    $equipmentCode = is_string($payload["equipment_code"] ?? null) ? trim($payload["equipment_code"]) : "";
    if ($equipmentCode === "" || mb_strlen($equipmentCode, "UTF-8") > 80
        || preg_match('/\A[A-Za-z0-9._-]{1,80}\z/', $equipmentCode) !== 1) {
        throw new ApiV1PayloadException("Informe o equipment_code de uma Dala vinculada a um PC industrial.");
    }

    $number = is_string($payload["number"] ?? null) ? trim($payload["number"]) : "";
    if ($number === "" || mb_strlen($number, "UTF-8") > 80
        || preg_match('/^[A-Za-z0-9À-ÿ _.,\/-]{1,80}$/u', $number) !== 1) {
        throw new ApiV1PayloadException("Informe um número de romaneio válido, com até 80 caracteres.");
    }

    $scheduledDate = is_string($payload["scheduled_date"] ?? null)
        ? trim($payload["scheduled_date"])
        : "";
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $scheduledDate);
    if ($date === false || $date->format("Y-m-d") !== $scheduledDate
        || (!$permitirDataPassada && $scheduledDate < date("Y-m-d"))) {
        throw new ApiV1PayloadException("scheduled_date deve ser hoje ou uma data futura no formato AAAA-MM-DD.");
    }

    $plateValue = is_string($payload["plate"] ?? null) ? trim($payload["plate"]) : "";
    $plate = mb_strtoupper($plateValue, "UTF-8");
    if (!placa_caminhao_valida($plate)) {
        throw new ApiV1PayloadException("Informe uma placa de caminhão válida.");
    }

    $expedidorValue = $payload["expedidor"] ?? "";
    if (!is_string($expedidorValue)) {
        throw new ApiV1PayloadException("expedidor deve ser texto.");
    }
    $expedidor = trim($expedidorValue);
    if ($expedidor !== "" && !nome_empresa_valido($expedidor)) {
        throw new ApiV1PayloadException("expedidor deve ter até 160 caracteres e não conter controles.");
    }

    $driverValue = $payload["driver_name"] ?? "";
    if (!is_string($driverValue)) {
        throw new ApiV1PayloadException("driver_name deve ser texto.");
    }
    $driverName = trim($driverValue);
    if ($driverName !== "" && !nome_empresa_valido($driverName)) {
        throw new ApiV1PayloadException("driver_name deve ter até 160 caracteres e não conter controles.");
    }

    $items = $payload["items"] ?? null;
    if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > 100) {
        throw new ApiV1PayloadException("Informe entre 1 e 100 itens com product_id ou product_code e quantity.");
    }

    $normalizedItems = [];
    foreach ($items as $index => $item) {
        if (!is_array($item) || array_is_list($item)) {
            throw new ApiV1PayloadException("O item " . ($index + 1) . " deve ser um objeto.");
        }
        if (array_diff(array_keys($item), ["product_id", "product_code", "quantity"]) !== []) {
            throw new ApiV1PayloadException("O item " . ($index + 1) . " contém campos não permitidos.");
        }

        $rawQuantity = $item["quantity"] ?? null;
        if (!is_int($rawQuantity) && !is_string($rawQuantity)) {
            throw new ApiV1PayloadException("A quantidade do item " . ($index + 1) . " deve ser um inteiro positivo.");
        }
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 4294967295) {
            throw new ApiV1PayloadException("A quantidade do item " . ($index + 1) . " deve ser um inteiro positivo.");
        }

        $hasProductId = array_key_exists("product_id", $item);
        $hasProductCode = array_key_exists("product_code", $item);
        if ($hasProductId === $hasProductCode) {
            throw new ApiV1PayloadException("Informe somente product_id ou product_code no item " . ($index + 1) . ".");
        }

        $productId = null;
        $productCode = null;
        if ($hasProductId) {
            $rawProductId = $item["product_id"];
            if (!is_int($rawProductId) && !is_string($rawProductId)) {
                throw new ApiV1PayloadException("product_id do item " . ($index + 1) . " deve ser um inteiro positivo.");
            }
            $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if ($productId === false) {
                throw new ApiV1PayloadException("product_id do item " . ($index + 1) . " deve ser um inteiro positivo.");
            }
        } else {
            $productCode = is_string($item["product_code"]) ? trim($item["product_code"]) : "";
            if ($productCode === "" || mb_strlen($productCode, "UTF-8") > 80
                || preg_match('/[\x00-\x1F\x7F]/u', $productCode) === 1) {
                throw new ApiV1PayloadException("product_code do item " . ($index + 1) . " é inválido.");
            }
        }
        $normalizedItems[] = [
            "product_id" => $productId,
            "product_code" => $productCode,
            "quantity" => $quantity,
        ];
    }

    return [
        "equipment_code" => $equipmentCode,
        "number" => $number,
        "scheduled_date" => $scheduledDate,
        "expedidor" => $expedidor !== "" ? $expedidor : null,
        "plate" => $plate,
        "driver_name" => $driverName !== "" ? $driverName : null,
        "items" => $normalizedItems,
    ];
}

/** @param array{id:int,company_id:int,scopes:list<string>} $client */
function api_v1_criar_romaneio(PDO $pdo, array $client): never
{
    $contentType = strtolower(trim(explode(";", (string) ($_SERVER["CONTENT_TYPE"] ?? ""))[0]));
    if ($contentType !== "application/json") {
        api_v1_responder_erro("unsupported_media_type", "Envie o corpo como application/json.", 415);
    }
    $rawBody = file_get_contents("php://input");
    if (!is_string($rawBody) || $rawBody === "") {
        api_v1_responder_erro("invalid_payload", "Envie os dados do romaneio em JSON.", 400);
    }
    if (strlen($rawBody) > 128 * 1024) {
        api_v1_responder_erro("payload_too_large", "O corpo da requisição ultrapassa 128 KB.", 413);
    }
    try {
        $decoded = json_decode($rawBody, false, 32, JSON_THROW_ON_ERROR);
        $decodedPayload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_v1_responder_erro("invalid_json", "O corpo da requisição não contém JSON válido.", 400);
    }
    if (!$decoded instanceof stdClass || !is_array($decodedPayload)) {
        api_v1_responder_erro("invalid_payload", "O corpo deve ser um objeto JSON.", 422);
    }

    try {
        $idempotencyKey = api_v1_chave_idempotencia(
            isset($_SERVER["HTTP_IDEMPOTENCY_KEY"]) ? (string) $_SERVER["HTTP_IDEMPOTENCY_KEY"] : null,
        );
        $payload = api_v1_normalizar_payload_romaneio($decodedPayload, true);
    } catch (ApiV1PayloadException $exception) {
        api_v1_responder_erro($exception->errorCode, $exception->getMessage(), 422);
    }

    try {
        $pdo->beginTransaction();
        $keyHash = hash("sha256", $idempotencyKey);
        $payloadHash = hash("sha256", json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $reserve = $pdo->prepare(
            "INSERT INTO api_romaneios_idempotencia
             (api_key_id, company_id, key_hash, payload_hash)
             VALUES (:api_key_id, :company_id, :key_hash, :payload_hash)
             ON DUPLICATE KEY UPDATE id = id",
        );
        $reserve->execute([
            "api_key_id" => $client["id"],
            "company_id" => $client["company_id"],
            "key_hash" => $keyHash,
            "payload_hash" => $payloadHash,
        ]);
        $reserved = $pdo->prepare(
            "SELECT id, payload_hash, response_json
             FROM api_romaneios_idempotencia
             WHERE api_key_id = :api_key_id AND key_hash = :key_hash FOR UPDATE",
        );
        $reserved->execute(["api_key_id" => $client["id"], "key_hash" => $keyHash]);
        $reservation = $reserved->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reservation) || !hash_equals((string) $reservation["payload_hash"], $payloadHash)) {
            $pdo->rollBack();
            api_v1_responder_erro("idempotency_conflict", "Idempotency-Key já foi usado com outro conteúdo.", 409);
        }
        if ($reservation["response_json"] !== null) {
            $original = json_decode((string) $reservation["response_json"], true, 32, JSON_THROW_ON_ERROR);
            $pdo->commit();
            header("Idempotent-Replayed: true");
            header("Content-Type: application/json; charset=utf-8");
            responder_json(["data" => $original], 200);
        }
        if ($payload["scheduled_date"] < date("Y-m-d")) {
            $pdo->rollBack();
            api_v1_responder_erro("invalid_payload", "scheduled_date deve ser hoje ou uma data futura no formato AAAA-MM-DD.", 422);
        }
        $installationQuery = $pdo->prepare(
            "SELECT i.id, e.id AS equipment_id, e.equipment_code
             FROM instalacoes_industriais i
             JOIN equipamentos e ON e.id = i.equipment_id AND e.company_id = i.company_id
             WHERE i.company_id = :company_id AND e.equipment_code = :equipment_code
               AND i.sync_token_hash IS NOT NULL
             LIMIT 2 FOR UPDATE",
        );
        $installationQuery->execute([
            "company_id" => $client["company_id"],
            "equipment_code" => $payload["equipment_code"],
        ]);
        $installations = $installationQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($installations) !== 1) {
            $pdo->rollBack();
            api_v1_responder_erro(
                "installation_not_found",
                "A Dala informada não tem exatamente um PC industrial vinculado e configurado para sincronização.",
                422,
            );
        }
        $installationId = (int) $installations[0]["id"];
        $productIds = [];
        $productCodes = [];
        foreach ($payload["items"] as $item) {
            if ($item["product_id"] !== null) {
                $productIds[] = $item["product_id"];
            } else {
                $productCodes[] = (string) $item["product_code"];
            }
        }
        $productRepository = new RepositorioProdutosRomaneio($pdo);
        $productsById = $productRepository->buscarAtivosPorIds((int) $client["company_id"], $productIds);
        $productsByCode = $productRepository->buscarAtivosPorCodigos((int) $client["company_id"], $productCodes);
        $resolvedItems = [];
        foreach ($payload["items"] as $item) {
            $product = $item["product_id"] !== null
                ? ($productsById[$item["product_id"]] ?? null)
                : ($productsByCode[mb_strtolower((string) $item["product_code"], "UTF-8")] ?? null);
            if (!is_array($product)) {
                $pdo->rollBack();
                api_v1_responder_erro("product_not_found", "Produto inexistente, inativo ou fora da empresa da chave.", 422);
            }
            $resolvedItems[] = ["product_id" => (int) $product["id"], "quantity" => $item["quantity"]];
        }

        $romaneio = $pdo->prepare(
            "INSERT INTO romaneios
             (company_id, assigned_installation_id, assigned_installation_company_id, number, scheduled_date, expedidor, status)
             VALUES (:company_id, :installation_id, :company_id, :number, :scheduled_date, :expedidor, 'AGUARDANDO')",
        );
        $romaneio->execute([
            "company_id" => $client["company_id"],
            "installation_id" => $installationId,
            "number" => $payload["number"],
            "scheduled_date" => $payload["scheduled_date"],
            "expedidor" => $payload["expedidor"],
        ]);
        $romaneioId = (int) $pdo->lastInsertId();

        $truck = $pdo->prepare(
            "INSERT INTO romaneio_caminhoes (romaneio_id, plate, driver_name)
             VALUES (:romaneio_id, :plate, :driver_name)",
        );
        $truck->execute([
            "romaneio_id" => $romaneioId,
            "plate" => $payload["plate"],
            "driver_name" => $payload["driver_name"],
        ]);
        $truckId = (int) $pdo->lastInsertId();

        (new RepositorioItensRomaneio($pdo))->inserirLinhas(
            (int) $client["company_id"],
            array_map(
                static fn (array $item): array => [
                    "romaneio_id" => $romaneioId,
                    "product_id" => $item["product_id"],
                    "truck_id" => $truckId,
                    "quantity" => $item["quantity"],
                ],
                $resolvedItems,
            ),
        );

        $metadata = json_encode([
            "integration_id" => $client["id"],
            "request_id" => trace_correlation_id(),
            "items" => count($resolvedItems),
            "truck_id" => $truckId,
            "installation_id" => $installationId,
            "equipment_code" => $payload["equipment_code"],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $audit = $pdo->prepare(
            "INSERT INTO logs_auditoria
             (event_uuid, company_id, user_id, action, entity_type, entity_id, metadata)
             VALUES (:event_uuid, :company_id, NULL, 'API_ROMANEIO_CRIADO', 'romaneio', :entity_id, :metadata)",
        );
        $audit->execute([
            "event_uuid" => trace_uuid_v4(),
            "company_id" => $client["company_id"],
            "entity_id" => $romaneioId,
            "metadata" => $metadata,
        ]);
        $responseData = [
            "id" => $romaneioId,
            "equipment_code" => $payload["equipment_code"],
            "number" => $payload["number"],
            "scheduled_date" => $payload["scheduled_date"],
            "truck_id" => $truckId,
            "status" => "AGUARDANDO",
        ];
        $complete = $pdo->prepare(
            "UPDATE api_romaneios_idempotencia
             SET romaneio_id = :romaneio_id, response_json = :response_json
             WHERE id = :id AND api_key_id = :api_key_id",
        );
        $complete->execute([
            "romaneio_id" => $romaneioId,
            "response_json" => json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            "id" => $reservation["id"],
            "api_key_id" => $client["id"],
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            api_v1_responder_erro("duplicate_romaneio", "Já existe um romaneio com este número para a Dala de destino.", 409);
        }
        registrar_log_erro($exception, "api_v1_romaneio_write");
        api_v1_responder_erro("internal_error", "Não foi possível salvar o romaneio.", 500);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        registrar_log_erro($exception, "api_v1_romaneio_write");
        api_v1_responder_erro("internal_error", "Não foi possível salvar o romaneio.", 500);
    }

    header("Location: /api/v1/romaneios/{$romaneioId}");
    header("Content-Type: application/json; charset=utf-8");
    responder_json(["data" => $responseData], 201);
}
