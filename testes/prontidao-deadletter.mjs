import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const prontidao = await readFile(
  new URL("../servidor/api/sistema/prontidao.php", import.meta.url),
  "utf8",
);

test("prontidão inicializa e expõe a contagem da fila morta pendente", () => {
  assert.match(prontidao, /SELECT COUNT\(\*\) FROM sync_dead_letter_queue\s+WHERE resolved_at IS NULL/);
  assert.match(prontidao, /\$deadLetterStatement->execute\(\$companyParams\)/);
  assert.match(prontidao, /\$deadLetterPending = \(int\) \$deadLetterStatement->fetchColumn\(\)/);
  assert.match(prontidao, /"dead_letter"\s*=>\s*\["pending"\s*=>\s*\$deadLetterPending\]/);
});

test("contagem da fila morta usa o escopo da instalação local", () => {
  assert.match(
    prontidao,
    /\(\$installationCompanyId === null \? "" : " AND company_id = :company_id"\)/,
  );
});
