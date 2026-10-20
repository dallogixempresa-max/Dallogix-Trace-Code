const TAMANHO_PAGINA_INTERNA = 100;
const LIMITE_PAGINAS_INTERNAS = 100;

/** Valida o cursor crescente usado pelas listas internas paginadas. */
export function validarCursorPaginacao(pagination, cursorAtual) {
  if (!pagination || typeof pagination.has_more !== "boolean") {
    throw new Error("O servidor retornou metadados de paginação inválidos.");
  }
  if (!pagination.has_more) return null;

  const nextCursor = Number(pagination.next_cursor);
  if (!Number.isSafeInteger(nextCursor) || nextCursor <= cursorAtual) {
    throw new Error("O servidor retornou um cursor de paginação inválido.");
  }
  return nextCursor;
}

/** Percorre uma lista paginada sem ultrapassar o teto de registros do cliente. */
export async function buscarTodasPaginas({
  api,
  jsonResponse,
  path,
  signal = undefined,
  cursorParam = "cursor",
}) {
  const url = new URL(path, "http://trace.local");
  const rows = [];
  let cursor = 0;

  for (let pageIndex = 0; pageIndex < LIMITE_PAGINAS_INTERNAS; pageIndex += 1) {
    url.searchParams.set("limit", String(TAMANHO_PAGINA_INTERNA));
    url.searchParams.set(cursorParam, String(cursor));
    const response = await api.fetch(`${url.pathname}${url.search}`, { signal });
    const result = await jsonResponse(response, "Não foi possível carregar os registros.");
    if (!Array.isArray(result.data) || typeof result.pagination?.has_more !== "boolean") {
      throw new Error("O servidor retornou uma página sem os dados esperados.");
    }

    rows.push(...result.data);
    const nextCursor = validarCursorPaginacao(result.pagination, cursor);
    if (nextCursor === null) return rows;
    cursor = nextCursor;
  }

  throw new Error("A lista ultrapassa 10.000 registros; use os filtros para reduzir a consulta.");
}
