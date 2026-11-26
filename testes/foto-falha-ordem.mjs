import test from "node:test";
import assert from "node:assert/strict";
import { matchFailurePhotoInOrder } from "../integracoes/keyence-worker/keyence-core.mjs";

const t = (hms) => Date.parse(`2026-11-26T${hms}Z`);
const falha = (id, passagem, extra = {}) => ({ id, sensor_event_id: id, reason: "SEM_LEITURA", status: "PENDENTE", image_sha256: null, closed_by_timeout: false, passage_at_epoch_ms: passagem, ...extra });

// Processa as fotos na ordem em que o leitor tirou, como o worker faz; a saca
// que recebe foto vira CAPTURADA e sai da fila.
function ligar(fotos, falhas) {
  const resultado = {};
  for (const [nome, hora] of fotos) {
    const match = matchFailurePhotoInOrder(hora, falhas);
    resultado[nome] = match.request?.sensor_event_id ?? null;
    if (match.request) match.request.status = "CAPTURADA";
  }
  return resultado;
}

test("teste 9b (26/11): sacas a 0,5 s, foto tirada depois da passagem seguinte — cada foto na própria saca", () => {
  const falhas = [
    falha(3449, t("21:14:59.135")),
    falha(3452, t("21:15:16.614")),
    falha(3455, t("21:15:31.357")),
    falha(3456, t("21:15:31.867")),
  ];
  const ligadas = ligar([
    ["010", t("21:14:59.656")], ["011", t("21:15:17.125")], ["012", t("21:15:31.880")], ["013", t("21:15:32.016")],
  ], falhas);
  assert.deepEqual(ligadas, { "010": 3449, "011": 3452, "012": 3455, "013": 3456 });
});

test("carregamento 151: foto da saca 7 tirada 2,6 s depois vai para ela", () => {
  const falhas = [
    falha(3366, t("20:08:13.587"), { status: "ERRO" }),
    falha(3373, t("20:09:13.498")),
    falha(3383, t("20:10:01.180")),
    falha(3389, t("20:11:10.197")),
  ];
  const ligadas = ligar([
    ["000", t("20:08:16.197")], ["001", t("20:09:13.902")], ["002", t("20:10:01.534")], ["003", t("20:11:10.328")],
  ], falhas);
  assert.deepEqual(ligadas, { "000": 3366, "001": 3373, "002": 3383, "003": 3389 });
});

test("saca que já tem foto nunca recebe outra", () => {
  const falhas = [falha(1, t("10:00:00.000"), { status: "CAPTURADA", image_sha256: "a".repeat(64) }), falha(2, t("10:00:00.500"))];
  assert.equal(matchFailurePhotoInOrder(t("10:00:01.000"), falhas).request?.id, 2);
});

test("saca fechada só pelo prazo (sem ERROR do leitor) não rouba a foto de uma falha real", () => {
  const falhas = [falha(1, t("10:00:00.000"), { closed_by_timeout: true }), falha(2, t("10:00:01.000"))];
  assert.equal(matchFailurePhotoInOrder(t("10:00:01.500"), falhas).request?.id, 2);
});

test("foto antes da passagem ou mais de 10 s depois não é ligada", () => {
  const falhas = [falha(1, t("10:00:05.000"))];
  assert.equal(matchFailurePhotoInOrder(t("10:00:04.000"), falhas).request, null);
  assert.equal(matchFailurePhotoInOrder(t("10:00:16.000"), falhas).request, null);
});

test("só SEM_LEITURA recebe foto de falha pela ordem", () => {
  const falhas = [{ ...falha(1, t("10:00:00.000")), reason: "PRODUTO_INCORRETO" }];
  assert.equal(matchFailurePhotoInOrder(t("10:00:00.500"), falhas).request, null);
});
