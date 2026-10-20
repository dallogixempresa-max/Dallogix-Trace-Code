<?php

declare(strict_types=1);

namespace App\Aplicacao;

use Closure;

/** Transporte HTTP da fila remota; não conhece persistência nem estado dos eventos. */
final class ClienteHttpSincronizacao
{
    private const TEMPO_LIMITE_CONEXAO_SEGUNDOS = 3;
    private const TEMPO_LIMITE_REQUISICAO_SEGUNDOS = 10;

    /**
     * @var Closure(string,string,list<string>,int,int):array{body:string|false,error:string,http_code:int,initialization_failed?:bool}
     */
    private readonly Closure $executarRequisicao;

    /**
     * O executor injetável permite validar respostas e timeouts sem depender da rede.
     *
     * @param null|Closure(string,string,list<string>,int,int):array{body:string|false,error:string,http_code:int,initialization_failed?:bool} $executor
     */
    public function __construct(?Closure $executor = null)
    {
        $this->executarRequisicao = $executor ?? Closure::fromCallable([$this, "executarComCurl"]);
    }

    /** @return array{ok:bool,error:string,http_code:int,response?:array<string,mixed>} */
    public function postarJson(string $remoteUrl, string $body, string $token): array
    {
        $headers = ["Accept: application/json", "Content-Type: application/json"];
        if ($token !== "") {
            if (preg_match('/[\r\n]/', $token) === 1) {
                return ["ok" => false, "error" => "Token de sincronização inválido.", "http_code" => 0];
            }
            $headers[] = "Authorization: Bearer " . $token;
        }

        $result = ($this->executarRequisicao)(
            $remoteUrl,
            $body,
            $headers,
            self::TEMPO_LIMITE_CONEXAO_SEGUNDOS,
            self::TEMPO_LIMITE_REQUISICAO_SEGUNDOS,
        );
        if (($result["initialization_failed"] ?? false) === true) {
            return [
                "ok" => false,
                "error" => "Não foi possível inicializar o cliente HTTP.",
                "http_code" => $result["http_code"],
            ];
        }
        $curlError = trim($result["error"]);
        $httpCode = $result["http_code"];

        if ($curlError !== "") {
            return ["ok" => false, "error" => "Falha de rede: " . $curlError, "http_code" => $httpCode];
        }

        $response = json_decode((string) $result["body"], true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $remoteError = is_array($response) ? trim((string) ($response["error"] ?? "")) : "";
            return [
                "ok" => false,
                "error" => $remoteError !== ""
                    ? "Endpoint remoto respondeu HTTP {$httpCode}: {$remoteError}"
                    : "Endpoint remoto respondeu HTTP {$httpCode}.",
                "http_code" => $httpCode,
            ];
        }

        if (!is_array($response) || !isset($response["data"]) || !is_array($response["data"])) {
            return [
                "ok" => false,
                "error" => "Endpoint remoto retornou uma resposta inválida.",
                "http_code" => $httpCode,
            ];
        }

        return ["ok" => true, "error" => "", "http_code" => $httpCode, "response" => $response];
    }

    /** @param list<string> $headers @return array{body:string|false,error:string,http_code:int} */
    private function executarComCurl(
        string $remoteUrl,
        string $body,
        array $headers,
        int $connectTimeout,
        int $requestTimeout,
    ): array {
        $handle = \curl_init_url_remota_segura($remoteUrl);
        if ($handle === false) {
            return ["body" => false, "error" => "", "http_code" => 0, "initialization_failed" => true];
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            return [
                "body" => curl_exec($handle),
                "error" => trim((string) curl_error($handle)),
                "http_code" => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            ];
        } finally {
            curl_close($handle);
        }
    }
}
