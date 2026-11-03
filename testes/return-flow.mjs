import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const endpoint = await readFile(new URL("../servidor/api/operacoes/retornos.php", import.meta.url), "utf8");

test("retorno bloqueia o carregamento antes de validar a leitura", () => {
  const transactionStart = endpoint.indexOf("$pdo->beginTransaction()");
  const loadingLock = endpoint.indexOf("FROM carregamentos WHERE id = :id AND company_id = :company_id LIMIT 1 FOR UPDATE");
  const readingSelect = endpoint.indexOf("SELECT l.id, l.product_id, l.barcode FROM leituras l");
  const duplicateLookup = endpoint.indexOf("SELECT action, metadata FROM logs_auditoria");
  const readingLock = endpoint.indexOf("LIMIT 1 FOR UPDATE", readingSelect);
  const returnInsert = endpoint.indexOf("INSERT INTO retornos");
  assert.ok(transactionStart >= 0 && transactionStart < loadingLock);
  assert.ok(loadingLock < readingSelect && readingSelect < readingLock && readingLock < returnInsert);
  assert.ok(loadingLock < duplicateLookup && duplicateLookup < readingSelect);
  assert.match(endpoint, /\["CARREGANDO", "FINALIZANDO", "PAUSADO"\]/);
  assert.match(endpoint, /NOT EXISTS \(SELECT 1 FROM retornos r WHERE r\.leitura_id = l\.id\)/);
});

test("reenvio de retorno só é idempotente para a mesma leitura e motivo", () => {
  assert.match(endpoint, /\$registered\["action"\] === "RETORNO_REGISTRADO"/);
  assert.match(endpoint, /\$original\["carregamento_id"\].*\$loadingId/);
  assert.match(endpoint, /\$original\["leitura_id"\].*\$readingId/);
  assert.match(endpoint, /\$original\["reason"\].*\$reason/);
});

test("conflito temporário no retorno pode ser repetido sem dupla contagem", () => {
  assert.match(endpoint, /\$exception->getCode\(\) === "40001"/);
  assert.match(endpoint, /in_array\(\$driverErrorCode, \[1205, 1213\], true\)/);
  assert.match(endpoint, /"retryable" => true\], 503\)/);
});
