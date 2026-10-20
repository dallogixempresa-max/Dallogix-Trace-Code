<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/AgrupadorItensImportacaoRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioItensRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioProdutosRomaneio.php";

use App\Aplicacao\AgrupadorItensImportacaoRomaneio;
use App\Persistencia\RepositorioItensRomaneio;
use App\Persistencia\RepositorioProdutosRomaneio;

final class ExcecaoValidacaoImportacaoCsv extends RuntimeException
{
}

$user = require_session_user();
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(["error" => "Método não permitido."], 405);
}
require_csrf();
if ($user["company_id"] === null) {
    json_response(["error" => "Usuário sem empresa vinculada."], 403);
}
$companyId = (int) $user["company_id"];
if (!in_array($user["role"], ["ADMIN_EMPRESA", "SUPERVISOR"], true)) {
    json_response(["error" => "Perfil sem permissão para importar romaneio."], 403);
}
if (!isset($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
    json_response(["error" => "Envie um arquivo CSV válido."], 422);
}
if ((int) ($_FILES["file"]["size"] ?? 0) < 1 || (int) ($_FILES["file"]["size"] ?? 0) > 5 * 1024 * 1024) {
    json_response(["error" => "CSV excede o limite de 5 MB."], 413);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["file"]["tmp_name"]);
if (!in_array($mime, ["text/csv", "text/plain", "application/csv", "application/vnd.ms-excel", "application/octet-stream"], true)) {
    json_response(["error" => "Tipo de arquivo inválido: envie um CSV."], 422);
}

$handle = fopen($_FILES["file"]["tmp_name"], "rb");
if ($handle === false) {
    json_response(["error" => "Não foi possível ler o arquivo CSV."], 422);
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    json_response(["error" => "CSV vazio ou sem linhas de dados."], 422);
}
$delimiter = substr_count($firstLine, ",") > substr_count($firstLine, ";") ? "," : ";";
rewind($handle);

$rows = [];
while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    if (
        count(
            array_filter(
                $row,
                static fn($value) => trim((string) $value) !== "",
            ),
        ) === 0
    ) {
        continue;
    }
    $rows[] = array_map(static fn($value) => trim((string) $value), $row);
    if (count($rows) > 10000) {
        json_response(
            ["error" => "CSV excede o limite de 10.000 linhas."],
            413,
        );
    }
}
fclose($handle);
if (count($rows) < 2) {
    json_response(["error" => "CSV vazio ou sem linhas de dados."], 422);
}

$header = array_map(
    static fn($value) => strtolower(
        trim(preg_replace('/^\xEF\xBB\xBF/', "", $value) ?? $value),
    ),
    $rows[0],
);
$required = ["romaneio", "data", "placa", "produto", "quantidade"];
foreach ($required as $column) {
    if (!in_array($column, $header, true)) {
        json_response(
            ["error" => "Coluna obrigatória ausente: {$column}."],
            422,
        );
    }
}
$index = array_flip($header);
$pdo = db();
$created = 0;
$romaneioIds = [];
$truckIds = [];
$itemRows = [];
$productCodes = array_map(
    static fn (array $row): string => trim((string) ($row[$index["produto"]] ?? "")),
    array_slice($rows, 1),
);
try {
    $pdo->beginTransaction();
    $productsByCode = (new RepositorioProdutosRomaneio($pdo))
        ->buscarAtivosPorCodigos($companyId, $productCodes);
    foreach (array_slice($rows, 1) as $line => $row) {
        $lineNumber = $line + 2;
        $number = trim((string) ($row[$index["romaneio"]] ?? ""));
        $dateValue = trim((string) ($row[$index["data"]] ?? ""));
        $plate = strtoupper(trim((string) ($row[$index["placa"]] ?? "")));
        $productCode = trim((string) ($row[$index["produto"]] ?? ""));
        $quantity = filter_var(
            $row[$index["quantidade"]] ?? null,
            FILTER_VALIDATE_INT,
        );
        $driverIndex = $index["motorista"] ?? null;
        $shipperIndex = $index["expedidor"] ?? null;
        $driverName = $driverIndex === null
            ? ""
            : trim((string) ($row[$driverIndex] ?? ""));
        $shipper = $shipperIndex === null
            ? ""
            : trim((string) ($row[$shipperIndex] ?? ""));
        $date = null;
        foreach (["Y-m-d", "d/m/Y"] as $format) {
            $candidate = DateTime::createFromFormat("!{$format}", $dateValue);
            $errors = DateTime::getLastErrors();
            $hasErrors = is_array($errors) && ($errors["warning_count"] > 0 || $errors["error_count"] > 0);
            if ($candidate && !$hasErrors && $candidate->format($format) === $dateValue) {
                $date = $candidate;
                break;
            }
        }
        if ($date) {
            $dateValue = $date->format("Y-m-d");
        }
        if (
            $number === "" ||
            mb_strlen($number) > 80 ||
            !preg_match('/^[A-Z0-9-]{7,8}$/', $plate) ||
            $productCode === "" ||
            $quantity === false ||
            $quantity < 1 ||
            !$date
        ) {
            throw new ExcecaoValidacaoImportacaoCsv(
                "Linha {$lineNumber} inválida. Informe romaneio, data (AAAA-MM-DD ou DD/MM/AAAA), placa Mercosul, produto e quantidade maior que zero.",
            );
        }
        if (mb_strlen($driverName) > 160 || mb_strlen($shipper) > 160) {
            throw new ExcecaoValidacaoImportacaoCsv(
                "Linha {$lineNumber} possui motorista ou expedidor acima de 160 caracteres.",
            );
        }

        $product = $productsByCode[mb_strtolower($productCode, "UTF-8")] ?? null;
        if (!$product) {
            throw new ExcecaoValidacaoImportacaoCsv(
                "Produto {$productCode} não encontrado na linha {$lineNumber}.",
            );
        }

        if (!isset($romaneioIds[$number])) {
            $romaneioStatement = $pdo->prepare(
                'INSERT INTO romaneios (company_id, number, scheduled_date, expedidor, status) VALUES (:company_id, :number, :scheduled_date, :expedidor, \'IMPORTADO\')',
            );
            $romaneioStatement->execute([
                "company_id" => $user["company_id"],
                "number" => $number,
                "scheduled_date" => $dateValue,
                "expedidor" => $shipper === "" ? null : $shipper,
            ]);
            $romaneioIds[$number] = (int) $pdo->lastInsertId();
        }
        $romaneioId = $romaneioIds[$number];
        $truckKey = $number . "|" . $plate;
        if (!isset($truckIds[$truckKey])) {
            $truckStatement = $pdo->prepare(
                "INSERT INTO romaneio_caminhoes (romaneio_id, plate, driver_name) VALUES (:romaneio_id, :plate, :driver_name)",
            );
            $truckStatement->execute([
                "romaneio_id" => $romaneioId,
                "plate" => $plate,
                "driver_name" => $driverName === "" ? null : $driverName,
            ]);
            $truckIds[$truckKey] = (int) $pdo->lastInsertId();
        }
        $truckId = $truckIds[$truckKey];
        $itemRows[] = [
            "romaneio_id" => $romaneioId,
            "product_id" => (int) $product["id"],
            "truck_id" => $truckId,
            "quantity" => (int) $quantity,
        ];
        $created++;
    }
    $itemTotals = (new AgrupadorItensImportacaoRomaneio())->agrupar($itemRows);
    (new RepositorioItensRomaneio($pdo))->inserirLinhas($companyId, $itemTotals);
    foreach ($romaneioIds as $number => $romaneioId) {
        record_operational_event(
            $pdo,
            $user,
            "ROMANEIO_IMPORTADO_CSV",
            "romaneio",
            $romaneioId,
            ["number" => $number, "items" => $created],
        );
    }
    // Registros, auditoria e fila local-first precisam ser atômicos.
    $pdo->commit();
    json_response(
        [
            "data" => [
                "romaneios" => count($romaneioIds),
                "linhas" => $created,
                "items" => count($itemTotals),
            ],
        ],
        201,
    );
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (
        $exception instanceof PDOException &&
        isset($exception->errorInfo[1]) &&
        (int) $exception->errorInfo[1] === 1062
    ) {
        json_response(
            ["error" => "O CSV contém romaneio ou caminhão duplicado."],
            409,
        );
    }
    if ($exception instanceof ExcecaoValidacaoImportacaoCsv) {
        json_response(["error" => $exception->getMessage()], 422);
    }
    error_log("CSV import failed: " . $exception->getMessage());
    json_response(
        ["error" => "Não foi possível concluir a importação do CSV. Tente novamente."],
        500,
    );
}
