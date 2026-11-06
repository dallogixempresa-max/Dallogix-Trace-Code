import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const endpoint = await readFile(
  new URL("../servidor/api/sincronizacao/sincronizacao_eventos.php", import.meta.url),
  "utf8",
);

test("a sincronização da pausa usa placeholders PDO únicos e todos são vinculados", () => {
  const block = endpoint.match(
    /\$pauseLoading = \$pdo->prepare\(\s*"(?<sql>UPDATE carregamentos[\s\S]*?)"\s*,\s*\);\s*\$pauseLoading->execute\(\[(?<bindings>[\s\S]*?)\]\);/,
  );
  assert.ok(block, "bloco SQL da pausa automática não encontrado");

  const placeholders = [...block.groups.sql.matchAll(/:([a-z][a-z0-9_]*)/gi)]
    .map((match) => match[1]);
  const uniquePlaceholders = [...new Set(placeholders)].sort();
  assert.deepEqual(placeholders.slice().sort(), uniquePlaceholders);

  const bindings = [...block.groups.bindings.matchAll(/"([a-z][a-z0-9_]*)"\s*=>/gi)]
    .map((match) => match[1])
    .sort();
  assert.deepEqual(bindings, uniquePlaceholders);
  assert.match(block.groups.bindings, /"stop_reason_set"\s*=>\s*\$stopReason/);
  assert.match(block.groups.bindings, /"stop_reason_match"\s*=>\s*\$stopReason/);
});
