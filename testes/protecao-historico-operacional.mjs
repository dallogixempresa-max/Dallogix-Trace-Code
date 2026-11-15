import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import test from "node:test";

test("exclusões e cancelamentos remotos preservam operações e histórico ativos", () => {
  const script = new URL("./protecao-historico-operacional.php", import.meta.url);
  const result = spawnSync("php", [script.pathname], { encoding: "utf8" });
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  assert.equal(result.stdout.trim(), "ok");
});
