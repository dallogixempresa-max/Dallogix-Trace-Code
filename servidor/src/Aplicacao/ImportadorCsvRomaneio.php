<?php

declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioItensRomaneio;
use App\Persistencia\RepositorioProdutosRomaneio;
use DateTime;
use PDO;
use PDOException;
use Throwable;

/**
 * Importa romaneios de um CSV tratando cada romaneio como unidade atômica.
 *
 * Primeiro todas as linhas são lidas e validadas sem gravar nada. Depois cada
 * romaneio sem erro grava num SAVEPOINT próprio: um romaneio inválido ou já
 * cadastrado não impede os demais. Campos opcionais (motorista e expedidor)
 * ausentes ou em formato de fórmula não rejeitam nada; viram pendências.
 */
final class ImportadorCsvRomaneio
{
    public const COLUNAS_OBRIGATORIAS = ["romaneio", "data", "placa", "produto", "quantidade"];
    private const TAMANHO_MAXIMO_NUMERO = 80;
    private const TAMANHO_MAXIMO_TEXTO_LIVRE = 160;
    private const QUANTIDADE_MAXIMA = 4294967295;
    private const LOTE_CONSULTA_EXISTENTES = 500;

    /** @var callable(PDO,int,array):void */
    private $registrarAuditoria;

    /**
     * @param callable(PDO,int,array):void $registrarAuditoria recebe a conexão,
     *        o id do romaneio gravado e o payload da auditoria; roda dentro do
     *        SAVEPOINT do romaneio para manter registro e auditoria atômicos.
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly int $companyId,
        private readonly ?int $installationId,
        callable $registrarAuditoria,
    ) {
        $this->registrarAuditoria = $registrarAuditoria;
    }

    /**
     * @param array<int,list<string>> $linhas registros do CSV indexados pelo
     *        número da linha no arquivo; o primeiro registro é o cabeçalho.
     * @return array{status:int,body:array<string,mixed>}
     */
    public function importar(array $linhas): array
    {
        if (count($linhas) < 2) {
            return ["status" => 422, "body" => ["error" => "CSV vazio ou sem linhas de dados."]];
        }

        $cabecalho = array_map(
            static fn ($valor): string => strtolower(trim(
                preg_replace('/^\xEF\xBB\xBF/', "", (string) $valor) ?? (string) $valor,
            )),
            reset($linhas),
        );
        foreach (self::COLUNAS_OBRIGATORIAS as $coluna) {
            if (!in_array($coluna, $cabecalho, true)) {
                return ["status" => 422, "body" => ["error" => "Coluna obrigatória ausente: {$coluna}."]];
            }
        }
        $indice = array_flip($cabecalho);
        $dados = array_slice($linhas, 1, null, true);

        $produtos = (new RepositorioProdutosRomaneio($this->connection))->buscarAtivosPorCodigoOuBarras(
            $this->companyId,
            array_map(
                static fn (array $linha): string => trim((string) ($linha[$indice["produto"]] ?? "")),
                array_values($dados),
            ),
        );

        $rejeitados = [];
        $romaneios = $this->agruparEValidar($dados, $indice, $produtos, $rejeitados);
        $existentes = $this->buscarNumerosExistentes(array_column($romaneios, "numero"));

        $importados = [];
        $ignorados = [];
        $this->connection->beginTransaction();
        try {
            $sequencia = 0;
            foreach ($romaneios as $chave => $romaneio) {
                if (isset($existentes[$chave])) {
                    $ignorados[] = ["romaneio" => $romaneio["numero"], "motivo" => "já cadastrado"];
                    continue;
                }
                if ($romaneio["erros"] !== []) {
                    foreach ($romaneio["erros"] as $erro) {
                        $rejeitados[] = ["romaneio" => $romaneio["numero"], ...$erro];
                    }
                    continue;
                }

                $resultado = $this->gravarRomaneio($romaneio, "importacao_csv_" . (++$sequencia));
                if ($resultado === "ignorado") {
                    $ignorados[] = ["romaneio" => $romaneio["numero"], "motivo" => "já cadastrado"];
                } elseif ($resultado === "falha") {
                    $rejeitados[] = [
                        "romaneio" => $romaneio["numero"],
                        "linha" => $romaneio["primeira_linha"],
                        "motivo" => "não foi possível gravar o romaneio; tente novamente",
                    ];
                } else {
                    $importados[] = [
                        "romaneio" => $romaneio["numero"],
                        "itens" => $resultado,
                        "pendencias" => $this->pendencias($romaneio),
                    ];
                }
            }
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        $resumo = ["importados" => $importados, "rejeitados" => $rejeitados, "ignorados" => $ignorados];
        if ($importados === []) {
            return [
                "status" => 422,
                "body" => ["error" => "Nenhum romaneio foi importado.", "data" => $resumo],
            ];
        }

        return ["status" => 201, "body" => ["data" => $resumo]];
    }

    /**
     * @param array<int,list<string>> $dados
     * @param array<string,int> $indice
     * @param array<string,array{id:int}> $produtos
     * @param list<array{romaneio:string,linha:int,motivo:string}> $rejeitados
     * @return array<string,array<string,mixed>>
     */
    private function agruparEValidar(array $dados, array $indice, array $produtos, array &$rejeitados): array
    {
        $romaneios = [];
        foreach ($dados as $numeroLinha => $linha) {
            $valor = static fn (string $coluna): string => isset($indice[$coluna])
                ? trim((string) ($linha[$indice[$coluna]] ?? ""))
                : "";

            $numero = $valor("romaneio");
            if ($numero === "") {
                $rejeitados[] = ["romaneio" => "", "linha" => $numeroLinha, "motivo" => "número do romaneio não informado"];
                continue;
            }
            $chave = mb_strtolower($numero, "UTF-8");
            if (!isset($romaneios[$chave])) {
                $romaneios[$chave] = [
                    "numero" => $numero,
                    "primeira_linha" => $numeroLinha,
                    "data" => null,
                    "expedidor" => null,
                    "expedidor_pendencias" => [],
                    "caminhoes" => [],
                    "itens" => [],
                    "erros" => [],
                ];
            }
            $romaneio = &$romaneios[$chave];
            $erros = [];

            if (mb_strlen($numero) > self::TAMANHO_MAXIMO_NUMERO) {
                $erros[] = "número do romaneio acima de 80 caracteres";
            }
            $data = $this->normalizarData($valor("data"));
            if ($data === null) {
                $erros[] = $valor("data") === ""
                    ? "data não informada"
                    : "data inválida (use AAAA-MM-DD ou DD/MM/AAAA)";
            }
            $placa = strtoupper($valor("placa"));
            if ($placa === "") {
                $erros[] = "placa não informada";
            } elseif (!\placa_caminhao_valida($placa)) {
                $erros[] = "placa {$placa} inválida";
            }
            $codigoProduto = $valor("produto");
            $produto = null;
            if ($codigoProduto === "") {
                $erros[] = "produto não informado";
            } else {
                $produto = $produtos[mb_strtolower($codigoProduto, "UTF-8")] ?? null;
                if ($produto === null) {
                    $erros[] = "produto {$codigoProduto} não encontrado ou inativo";
                }
            }
            $quantidadeBruta = $valor("quantidade");
            $quantidade = filter_var($quantidadeBruta, FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 1, "max_range" => self::QUANTIDADE_MAXIMA],
            ]);
            if ($quantidade === false) {
                $erros[] = $quantidadeBruta === ""
                    ? "quantidade não informada"
                    : "quantidade inválida (use um número inteiro maior que zero)";
            }

            if ($erros !== []) {
                $romaneio["erros"][] = ["linha" => $numeroLinha, "motivo" => implode("; ", $erros)];
                unset($romaneio);
                continue;
            }

            $romaneio["data"] ??= $data;
            [$expedidor, $pendenciaExpedidor] = $this->limparTextoLivre($valor("expedidor"), "expedidor", $numeroLinha);
            if ($pendenciaExpedidor !== null) {
                $romaneio["expedidor_pendencias"][] = $pendenciaExpedidor;
            }
            $romaneio["expedidor"] ??= $expedidor;

            [$motorista, $pendenciaMotorista] = $this->limparTextoLivre($valor("motorista"), "motorista", $numeroLinha);
            if (!isset($romaneio["caminhoes"][$placa])) {
                $romaneio["caminhoes"][$placa] = ["motorista" => null, "pendencias" => []];
            }
            if ($pendenciaMotorista !== null) {
                $romaneio["caminhoes"][$placa]["pendencias"][] = $pendenciaMotorista;
            }
            $romaneio["caminhoes"][$placa]["motorista"] ??= $motorista;

            $romaneio["itens"][] = [
                "placa" => $placa,
                "product_id" => (int) $produto["id"],
                "quantity" => (int) $quantidade,
            ];
            unset($romaneio);
        }

        return $romaneios;
    }

    /**
     * Texto livre opcional: vazio, "-" ou só espaços vira NULL; prefixo de
     * fórmula (= + - @) é removido para o valor não ser interpretado por
     * planilhas na exportação. Nunca rejeita a linha.
     *
     * @return array{0:?string,1:?string} valor limpo e pendência de ajuste
     */
    private function limparTextoLivre(string $valor, string $campo, int $numeroLinha): array
    {
        $limpo = ltrim($valor, "=+-@ \t\r\n");
        $pendencia = null;
        if ($limpo !== $valor && $limpo !== "") {
            $pendencia = "linha {$numeroLinha}: {$campo} começava com caractere de fórmula e foi gravado sem ele";
        }
        if (mb_strlen($limpo) > self::TAMANHO_MAXIMO_TEXTO_LIVRE) {
            $limpo = mb_substr($limpo, 0, self::TAMANHO_MAXIMO_TEXTO_LIVRE);
            $pendencia = "linha {$numeroLinha}: {$campo} acima de 160 caracteres foi cortado";
        }

        return [$limpo === "" ? null : $limpo, $pendencia];
    }

    private function normalizarData(string $valor): ?string
    {
        foreach (["Y-m-d", "d/m/Y"] as $formato) {
            $candidata = DateTime::createFromFormat("!{$formato}", $valor);
            $erros = DateTime::getLastErrors();
            $temErros = is_array($erros) && ($erros["warning_count"] > 0 || $erros["error_count"] > 0);
            if ($candidata && !$temErros && $candidata->format($formato) === $valor) {
                return $candidata->format("Y-m-d");
            }
        }

        return null;
    }

    /** @param array<string,mixed> $romaneio @return list<string> */
    private function pendencias(array $romaneio): array
    {
        $pendencias = [];
        if ($romaneio["expedidor"] === null) {
            $pendencias[] = "expedidor não informado";
        }
        array_push($pendencias, ...$romaneio["expedidor_pendencias"]);
        foreach ($romaneio["caminhoes"] as $placa => $caminhao) {
            if ($caminhao["motorista"] === null) {
                $pendencias[] = "motorista não informado (placa {$placa})";
            }
            array_push($pendencias, ...$caminhao["pendencias"]);
        }

        return array_values(array_unique($pendencias));
    }

    /**
     * @param array<string,mixed> $romaneio
     * @return int|"ignorado"|"falha" quantidade de itens gravados ou o motivo da recusa
     */
    private function gravarRomaneio(array $romaneio, string $savepoint): int|string
    {
        $this->connection->exec("SAVEPOINT {$savepoint}");
        $etapa = "romaneio";
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO romaneios (company_id, assigned_installation_id, assigned_installation_company_id, number, scheduled_date, expedidor, status) VALUES (:company_id, :installation_id, :installation_company_id, :number, :scheduled_date, :expedidor, \'IMPORTADO\')',
            );
            $statement->execute([
                "company_id" => $this->companyId,
                "installation_id" => $this->installationId,
                "installation_company_id" => $this->installationId !== null ? $this->companyId : null,
                "number" => $romaneio["numero"],
                "scheduled_date" => $romaneio["data"],
                "expedidor" => $romaneio["expedidor"],
            ]);
            $romaneioId = (int) $this->connection->lastInsertId();
            $etapa = "itens";

            $caminhoes = [];
            $statement = $this->connection->prepare(
                "INSERT INTO romaneio_caminhoes (romaneio_id, plate, driver_name) VALUES (:romaneio_id, :plate, :driver_name)",
            );
            foreach ($romaneio["caminhoes"] as $placa => $caminhao) {
                $statement->execute([
                    "romaneio_id" => $romaneioId,
                    "plate" => (string) $placa,
                    "driver_name" => $caminhao["motorista"],
                ]);
                $caminhoes[$placa] = (int) $this->connection->lastInsertId();
            }

            $itens = (new AgrupadorItensImportacaoRomaneio())->agrupar(array_map(
                static fn (array $item): array => [
                    "romaneio_id" => $romaneioId,
                    "product_id" => $item["product_id"],
                    "truck_id" => $caminhoes[$item["placa"]],
                    "quantity" => $item["quantity"],
                ],
                $romaneio["itens"],
            ));
            (new RepositorioItensRomaneio($this->connection))->inserirLinhas($this->companyId, $itens);
            ($this->registrarAuditoria)($this->connection, $romaneioId, [
                "number" => $romaneio["numero"],
                "items" => count($romaneio["itens"]),
            ]);
            $this->connection->exec("RELEASE SAVEPOINT {$savepoint}");

            return count($itens);
        } catch (Throwable $exception) {
            // Se o banco já desfez a transação inteira (ex.: deadlock), o
            // ROLLBACK TO falha e a exceção original sobe para o endpoint.
            try {
                $this->connection->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->connection->exec("RELEASE SAVEPOINT {$savepoint}");
            } catch (Throwable) {
                throw $exception;
            }
            if ($etapa === "romaneio" && $this->violouUnicidade($exception)) {
                return "ignorado";
            }
            error_log("CSV import: romaneio {$romaneio['numero']} não gravado: " . $exception->getMessage());

            return "falha";
        }
    }

    private function violouUnicidade(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $info = $exception->errorInfo ?? [];

        return (int) ($info[1] ?? 0) === 1062
            || ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === "sqlite" && ($info[0] ?? "") === "23000");
    }

    /**
     * Checagem prévia de romaneios já cadastrados. A comparação final usa
     * minúsculas, como a collation do MySQL; o 1062 no INSERT cobre a corrida.
     *
     * @param list<string> $numeros @return array<string,true>
     */
    private function buscarNumerosExistentes(array $numeros): array
    {
        $existentes = [];
        foreach (array_chunk($numeros, self::LOTE_CONSULTA_EXISTENTES) as $lote) {
            $params = ["company_id" => $this->companyId];
            $placeholders = [];
            foreach ($lote as $posicao => $numero) {
                $placeholders[] = ":numero_{$posicao}";
                $params["numero_{$posicao}"] = $numero;
            }
            $statement = $this->connection->prepare(
                "SELECT number FROM romaneios WHERE company_id = :company_id AND number IN (" . implode(", ", $placeholders) . ")",
            );
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $numero) {
                $existentes[mb_strtolower((string) $numero, "UTF-8")] = true;
            }
        }

        return $existentes;
    }
}
