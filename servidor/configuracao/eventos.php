<?php

declare(strict_types=1);

function registrar_evento_operacional(
    PDO $conexao,
    array $usuario,
    string $acao,
    string $tipoEntidade,
    int $entidadeId,
    array $payload = [],
): void {
    $payload = trace_payload_com_correlacao($payload);
    // Um administrador da plataforma pode operar sobre uma empresa-alvo. A
    // empresa do ator continua sendo a fonte principal; no escopo Master, a
    // operação deve informar company_id no payload para manter a fila isolada.
    $companyId = filter_var(
        $usuario["company_id"] ?? $payload["company_id"] ?? null,
        FILTER_VALIDATE_INT,
    );
    $platformEvent = ($companyId === false || $companyId === null || (int) $companyId < 1)
        && ($usuario["role"] ?? "") === "ADMIN_DALLOGIX";
    if (!$platformEvent && ($companyId === false || $companyId === null || (int) $companyId < 1)) {
        throw new RuntimeException("Evento operacional sem empresa vinculada.");
    }

    $providedEventUuid = trim((string) ($payload["event_uuid"] ?? ""));
    $eventUuid = preg_match('/^[a-f0-9-]{16,80}$/i', $providedEventUuid)
        ? $providedEventUuid
        : trace_uuid_v4();

    $metadata = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    $auditoria = $conexao->prepare(
        "INSERT INTO logs_auditoria (event_uuid, company_id, user_id, action, entity_type, entity_id, metadata) VALUES (:event_uuid, :company_id, :user_id, :action, :entity_type, :entity_id, :metadata)",
    );
    $auditoria->execute([
        "event_uuid" => $eventUuid,
        "company_id" => $platformEvent ? null : (int) $companyId,
        "user_id" => $usuario["id"] ?? null,
        "action" => $acao,
        "entity_type" => $tipoEntidade,
        "entity_id" => $entidadeId,
        "metadata" => $metadata,
    ]);

    $payloadSincronizacao = json_encode(
        [
            "action" => $acao,
            "entity_type" => $tipoEntidade,
            "entity_id" => $entidadeId,
            "data" => $payload,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    if ($platformEvent) {
        // Eventos do administrador da plataforma não pertencem a uma empresa
        // e, portanto, não entram na fila de sincronização multiempresa.
        return;
    }
    $fila = $conexao->prepare(
        "INSERT INTO fila_sincronizacao (company_id, event_uuid, aggregate_type, aggregate_id, payload) VALUES (:company_id, :event_uuid, :aggregate_type, :aggregate_id, :payload)",
    );
    $fila->execute([
        "company_id" => (int) $companyId,
        "event_uuid" => $eventUuid,
        "aggregate_type" => $tipoEntidade,
        "aggregate_id" => $entidadeId,
        "payload" => $payloadSincronizacao,
    ]);
}

function enfileirar_evento_sincronizacao(
    PDO $conexao,
    array $usuario,
    string $acao,
    string $tipoEntidade,
    int $entidadeId,
    array $payload = [],
): void {
    $payload = trace_payload_com_correlacao($payload);
    $companyId = filter_var(
        $usuario["company_id"] ?? $payload["company_id"] ?? null,
        FILTER_VALIDATE_INT,
    );
    if ($companyId === false || $companyId === null || (int) $companyId < 1) {
        throw new RuntimeException("Evento de sincronização sem empresa vinculada.");
    }

    $eventUuid = trace_uuid_v4();
    $payloadSincronizacao = json_encode(
        [
            "action" => $acao,
            "entity_type" => $tipoEntidade,
            "entity_id" => $entidadeId,
            "data" => $payload,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $fila = $conexao->prepare(
        "INSERT INTO fila_sincronizacao (company_id, event_uuid, aggregate_type, aggregate_id, payload) VALUES (:company_id, :event_uuid, :aggregate_type, :aggregate_id, :payload)",
    );
    $fila->execute([
        "company_id" => (int) $companyId,
        "event_uuid" => $eventUuid,
        "aggregate_type" => $tipoEntidade,
        "aggregate_id" => $entidadeId,
        "payload" => $payloadSincronizacao,
    ]);
}

/** @deprecated Use registrar_evento_operacional() in new endpoints. */
function record_operational_event(
    PDO $connection,
    array $user,
    string $action,
    string $entityType,
    int $entityId,
    array $payload = [],
): void {
    registrar_evento_operacional($connection, $user, $action, $entityType, $entityId, $payload);
}

function registrar_log_erro(Throwable $exception, string $origem = "api"): void
{
    $usuario = obter_usuario_sessao();
    $mensagem = trim($exception->getMessage()) ?: "Falha inesperada.";
    $mensagem = trace_redigir_segredos($mensagem);
    $mensagem = mb_substr($mensagem, 0, 1000);
    error_log("Dallogix Trace [{$origem}]: {$mensagem}");

    try {
        $contexto = json_encode(
            [
                "correlation_id" => trace_correlation_id(),
                "tipo" => get_class($exception),
                "arquivo" => basename($exception->getFile()),
                "linha" => $exception->getLine(),
                "metodo" => $_SERVER["REQUEST_METHOD"] ?? "CLI",
                "rota" => strtok($_SERVER["REQUEST_URI"] ?? "", "?"),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($contexto === false) {
            $contexto = "{}";
        }

        $statement = db()->prepare(
            "INSERT INTO logs_erros (company_id, user_id, origem, mensagem, contexto) VALUES (:company_id, :user_id, :origem, :mensagem, :contexto)",
        );
        $statement->execute([
            "company_id" => $usuario["company_id"] ?? null,
            "user_id" => $usuario["id"] ?? null,
            "origem" => mb_substr($origem, 0, 120),
            "mensagem" => $mensagem,
            "contexto" => $contexto,
        ]);
    } catch (Throwable $loggingException) {
        $loggingMessage = trace_redigir_segredos($loggingException->getMessage());
        error_log("Dallogix Trace: falha ao persistir log [" . mb_substr($loggingMessage, 0, 500) . "].");
    }
}

set_exception_handler(static function (Throwable $exception): void {
    registrar_log_erro($exception, "erro_nao_tratado");
    if (!headers_sent()) {
        responder_json(["error" => "Ocorreu um erro inesperado. Consulte os logs do sistema."], 500);
    }
});
