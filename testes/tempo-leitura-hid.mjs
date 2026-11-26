import test from "node:test";
import assert from "node:assert/strict";
import { isScannerNoReadMarker, separarTempoDeLeitura } from "../interface/js/controladores/captura-leitor-hid.js";

test("separa o tempo de leitura que o Keyence anexa ao resultado", () => {
  assert.deepEqual(separarTempoDeLeitura("7898250782479,0420"), { codigo: "7898250782479", tempoMs: 420 });
  assert.deepEqual(separarTempoDeLeitura("ERROR,1000"), { codigo: "ERROR", tempoMs: 1000 });
  assert.deepEqual(separarTempoDeLeitura("7898250782479"), { codigo: "7898250782479", tempoMs: null });
  assert.deepEqual(separarTempoDeLeitura("ABC,99999"), { codigo: "ABC,99999", tempoMs: null });
});

test("ERROR com tempo de leitura continua sendo falha de leitura", () => {
  assert.equal(isScannerNoReadMarker("ERROR,1000"), true);
  assert.equal(isScannerNoReadMarker("ERROR"), true);
  assert.equal(isScannerNoReadMarker("7898250782479,0420"), false);
});

test("o texto inteiro vai ao servidor e a tela mostra só o código", async () => {
  const { readFile } = await import("node:fs/promises");
  const app = await readFile(new URL("../interface/js/aplicacao.js", import.meta.url), "utf8");
  assert.match(app, /display\.textContent = shown \? separarTempoDeLeitura\(shown\)\.codigo/);
  assert.match(app, /store\.registerScannerReading\(barcode, capturedAtEpochMs\)/);
  const leituras = await readFile(new URL("../servidor/api/operacoes/leituras.php", import.meta.url), "utf8");
  assert.match(leituras, /trace_split_scanner_read_time\(/);
  assert.match(leituras, /\$scannerReadTimeMs,\n\s*\);/);
  assert.match(leituras, /trace_record_trigger_link\(/);
});
