import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { isScannerNoReadMarker } from "../interface/js/controladores/captura-leitor-hid.js";
import { isKeyenceNoReadFrame } from "../integracoes/keyence-worker/keyence-core.mjs";

const solicitacaoLeitura = fileURLToPath(
  new URL("../servidor/src/Aplicacao/SolicitacaoLeitura.php", import.meta.url),
);

const cases = [
  ["ERROR", true],
  ["error", true],
  ["ERROR7898538751531", true],
  ["ERROR-7898538751531", true],
  ["ERROR:12345678", true],
  ["ERROR_12345678", true],
  ["ERROR 12345678", true],
  ["ERRORABC", false],
  ["ERROR-ABC", false],
  ["ERROR--12345678", false],
  ["7898538751531", false],
];

function serverNoRead(barcode) {
  const script = `
    require ${JSON.stringify(solicitacaoLeitura)};
    $r = App\\Aplicacao\\SolicitacaoLeitura::fromPayload(
      ["carregamento_id" => 1, "barcode" => $argv[1]],
      "",
    );
    echo $r->noRead ? "1" : "0";
  `;
  return execFileSync("php", ["-r", script, barcode], { encoding: "utf8" }) === "1";
}

test("tela, worker Keyence e servidor reconhecem o mesmo marcador de falha", () => {
  for (const [value, expected] of cases) {
    assert.equal(isScannerNoReadMarker(value), expected, `tela: ${value}`);
    assert.equal(isKeyenceNoReadFrame(value), expected, `keyence: ${value}`);
    assert.equal(serverNoRead(value), expected, `servidor: ${value}`);
  }
});
