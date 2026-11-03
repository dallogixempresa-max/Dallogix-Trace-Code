import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import test from "node:test";

test("snapshot de produtos preserva linhas idênticas e edições locais pendentes", () => {
  const script = new URL("./produtos-remotos.php", import.meta.url);
  const result = spawnSync("php", [script.pathname], { encoding: "utf8" });
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  assert.equal(result.stdout.trim(), "ok");
});
