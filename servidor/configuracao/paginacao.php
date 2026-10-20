<?php

declare(strict_types=1);

/** @return array{limit:int,cursor:int} */
function parametros_paginacao_lista(
    array $query,
    int $limitDefault = 100,
    int $limitMaximum = 100,
): array {
    $limit = filter_var(
        $query["limit"] ?? $limitDefault,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1, "max_range" => $limitMaximum]],
    );
    $cursor = filter_var(
        $query["cursor"] ?? 0,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 0]],
    );
    if ($limit === false || $cursor === false) {
        responder_json([
            "error" => "Paginação inválida. Use limit entre 1 e {$limitMaximum} e cursor inteiro não negativo.",
        ], 422);
    }

    return ["limit" => $limit, "cursor" => $cursor];
}

/** @param list<array<string,mixed>> $rows
 *  @return array{data:list<array<string,mixed>>,pagination:array{limit:int,next_cursor:?int,has_more:bool}}
 */
function pagina_lista_por_id(array $rows, int $limit): array
{
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    $last = $rows === [] ? null : end($rows);
    $nextCursor = $hasMore && is_array($last) && isset($last["id"])
        ? (int) $last["id"]
        : null;

    return [
        "data" => array_values($rows),
        "pagination" => [
            "limit" => $limit,
            "next_cursor" => $nextCursor,
            "has_more" => $hasMore,
        ],
    ];
}
