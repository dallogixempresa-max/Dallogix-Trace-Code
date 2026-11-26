<?php
declare(strict_types=1);

require_once __DIR__ . "/../../configuracao/bootstrap.php";
require_once __DIR__ . "/../../src/Aplicacao/AgrupadorItensImportacaoRomaneio.php";
require_once __DIR__ . "/../../src/Aplicacao/ImportadorCsvRomaneio.php";
require_once __DIR__ . "/../../src/Aplicacao/ResolvedorInstalacaoDestinatariaRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioItensRomaneio.php";
require_once __DIR__ . "/../../src/Persistencia/RepositorioProdutosRomaneio.php";

use App\Aplicacao\ImportadorCsvRomaneio;
use App\Aplicacao\ResolvedorInstalacaoDestinatariaRomaneio;

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

$pdo = db();
try {
    $resolvedInstallationId = ResolvedorInstalacaoDestinatariaRomaneio::resolver(
        $pdo,
        $companyId,
        (int) $user["id"],
        $_POST["installation_id"] ?? null,
    );
} catch (DomainException $exception) {
    json_response(["error" => $exception->getMessage()], 422);
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

// Registros indexados pelo número da linha no arquivo, para o relatório
// apontar a linha certa mesmo quando há linhas em branco no meio.
$rows = [];
$lineNumber = 0;
while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNumber++;
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
    $rows[$lineNumber] = array_map(static fn($value) => trim((string) $value), $row);
    if (count($rows) > 10000) {
        fclose($handle);
        json_response(
            ["error" => "CSV excede o limite de 10.000 linhas."],
            413,
        );
    }
}
fclose($handle);

try {
    $resultado = (new ImportadorCsvRomaneio(
        $pdo,
        $companyId,
        $resolvedInstallationId,
        static function (PDO $connection, int $romaneioId, array $payload) use ($user): void {
            record_operational_event(
                $connection,
                $user,
                "ROMANEIO_IMPORTADO_CSV",
                "romaneio",
                $romaneioId,
                $payload,
            );
        },
    ))->importar($rows);
} catch (Throwable $exception) {
    error_log("CSV import failed: " . $exception->getMessage());
    json_response(
        ["error" => "Não foi possível concluir a importação do CSV. Tente novamente."],
        500,
    );
}
json_response($resultado["body"], $resultado["status"]);
