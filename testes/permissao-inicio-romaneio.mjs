import assert from "node:assert/strict";
import test from "node:test";
import { ROLE_PAGES } from "../interface/js/configuracaoAplicacao.js";
import { manifestView } from "../interface/telas/romaneio/romaneio.js";

function renderManifest({ role = "USUARIO", status = "AGUARDANDO", activeLoadingId = null } = {}) {
  return manifestView({
    state: {
      userRole: role,
      manifestDetail: {
        id: 33,
        number: "0511",
        status,
        active_loading_id: activeLoadingId,
        loading_count: 0,
        pending_evidence_count: 0,
        planned_quantity: 15,
        detected_quantity: 0,
        loaded_quantity: 0,
        returned_quantity: 0,
        items: [],
        trucks: [],
      },
    },
  });
}

test("Operador pode abrir a preparação e iniciar um romaneio aguardando", () => {
  assert.ok(ROLE_PAGES.USUARIO.includes("division"));
  const html = renderManifest();
  assert.match(html, /data-action="prepare-manifest"/);
  assert.match(html, /Preparar carregamento/);
  assert.doesNotMatch(html, /data-action="cancel-manifest"/);
});

test("Operador não vê preparação se o romaneio já está ativo ou encerrado", () => {
  assert.doesNotMatch(renderManifest({ status: "EM_ANDAMENTO", activeLoadingId: 7 }), /data-action="prepare-manifest"/);
  assert.doesNotMatch(renderManifest({ status: "FINALIZADO" }), /data-action="prepare-manifest"/);
});
