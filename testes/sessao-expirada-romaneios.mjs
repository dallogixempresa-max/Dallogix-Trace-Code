import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const application = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
const storage = await readFile(new URL("../interface/js/classes/ArmazenamentoTrace.js", import.meta.url), "utf8");

test("consultas dos romaneios usam o cliente que redireciona quando a sessão expira", () => {
  assert.match(application, /const api = new ClienteApi\(\{\s*onUnauthorized:/);
  assert.match(application, /const store = new ArmazenamentoTrace\(\{ api \}\)/);
  assert.match(storage, /constructor\(\{ api = null \} = \{\}\)/);
  assert.match(storage, /this\.api = api \|\| new ClienteApi\(\)/);
});
