import test from "node:test";
import assert from "node:assert/strict";
import { createHidScannerCapture, separarResultadosDoLeitor } from "../interface/js/controladores/captura-leitor-hid.js";

const textos = (valor) => separarResultadosDoLeitor(valor).map((parte) => parte.texto);

test("separa resultados que o Wedge entregou grudados", () => {
  assert.deepEqual(textos("7898250782479ERROR"), ["7898250782479", "ERROR"]);
  assert.deepEqual(textos("ERROR7898250782943"), ["ERROR", "7898250782943"]);
  assert.deepEqual(textos("78982507824797898250782943"), ["7898250782479", "7898250782943"]);
  assert.deepEqual(textos("7898250782479,0420ERROR,1000"), ["7898250782479,0420", "ERROR,1000"]);
  assert.deepEqual(textos("ERRORERROR"), ["ERROR", "ERROR"]);
});

test("um resultado só continua como veio", () => {
  assert.deepEqual(textos("7898250782479"), ["7898250782479"]);
  assert.deepEqual(textos("ERROR"), ["ERROR"]);
  assert.deepEqual(textos("7898250782479,0420"), ["7898250782479,0420"]);
  // Dígito verificador inválido: não é separado nem inventado.
  assert.deepEqual(textos("7898250782470ERROR"), ["7898250782470ERROR"]);
  assert.deepEqual(textos("ABC123"), ["ABC123"]);
});

test("a captura envia cada resultado grudado em ordem, com a hora da própria tecla", async () => {
  let agora = 1000;
  const timers = [];
  const enviados = [];
  const captura = createHidScannerCapture({
    isEnabled: () => true,
    getLoadingId: () => 150,
    onBarcode: (codigo, hora, carga) => enviados.push([codigo, hora, carga]),
    now: () => agora,
    setTimer: (fn) => { timers.push(fn); return timers.length; },
    clearTimer: () => {},
  });
  for (const tecla of "7898250782479ERROR") {
    captura.handleKeyDown({ key: tecla, target: null });
    agora += 10;
  }
  captura.handleKeyDown({ key: "Enter", target: null });
  assert.deepEqual(enviados, [
    ["7898250782479", 1000, 150],
    ["ERROR", 1130, 150],
  ]);
});
