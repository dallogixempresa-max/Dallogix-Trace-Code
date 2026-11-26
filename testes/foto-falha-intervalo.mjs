import test from "node:test";
import assert from "node:assert/strict";
import { matchUniquePendingImage } from "../integracoes/keyence-worker/keyence-core.mjs";

// Carregamento 151 (26/11/2026, 17:08–17:11): o leitor tirou 4 fotos de falha,
// uma por saca sem leitura. A da saca 7 saiu 2,6 s depois da passagem, fora da
// antiga janela de 1,5 s, e ficou sem vínculo.
const TRAVEL = 179; // image_expected_at continua com o deslocamento; o intervalo não.
const passagem = (id, eventoMs, proximaMs, status = "PENDENTE") => ({
  id,
  sensor_event_id: id,
  status,
  image_expected_at_epoch_ms: eventoMs + TRAVEL,
  image_valid_from_epoch_ms: eventoMs,
  image_valid_until_epoch_ms: proximaMs == null ? null : proximaMs,
});
const t = (hms) => Date.parse(`2026-11-26T${hms}Z`);
const falhas = [
  passagem(3366, t("20:08:13.587"), t("20:08:24.539"), "ERRO"),
  passagem(3373, t("20:09:13.498"), t("20:09:13.926")),
  passagem(3383, t("20:10:01.180"), t("20:10:16.140")),
  passagem(3389, t("20:11:10.197"), t("20:11:10.628")),
];

test("cada foto de falha do carregamento 151 vai para a própria saca", () => {
  const fotos = [["20:08:16.197", 3366], ["20:09:13.902", 3373], ["20:10:01.534", 3383], ["20:11:10.328", 3389]];
  for (const [hora, esperado] of fotos) {
    const match = matchUniquePendingImage(t(hora), falhas, 1500, "image_expected_at_epoch_ms");
    assert.equal(match.request?.sensor_event_id, esperado, `foto das ${hora}`);
  }
});

test("foto atrasada (saca 7, 2,6 s depois) é aceita, inclusive com a solicitação já em ERRO", () => {
  const match = matchUniquePendingImage(t("20:08:16.197"), falhas, 1500, "image_expected_at_epoch_ms");
  assert.equal(match.request?.sensor_event_id, 3366);
});

test("foto antes da passagem ou depois da passagem seguinte não vai para a saca", () => {
  assert.equal(matchUniquePendingImage(t("20:08:13.000"), falhas, 1500, "image_expected_at_epoch_ms").request, null);
  assert.equal(matchUniquePendingImage(t("20:08:30.000"), falhas, 1500, "image_expected_at_epoch_ms").request, null);
});

test("última saca do carregamento (sem passagem seguinte) aceita a foto até 10 s depois", () => {
  const ultima = [passagem(3400, t("20:12:00.000"), null)];
  assert.equal(matchUniquePendingImage(t("20:12:04.000"), ultima, 1500, "image_expected_at_epoch_ms").request?.sensor_event_id, 3400);
  assert.equal(matchUniquePendingImage(t("20:12:20.000"), ultima, 1500, "image_expected_at_epoch_ms").request, null);
});
