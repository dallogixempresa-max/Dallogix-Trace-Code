import assert from "node:assert/strict";
import { ServicoInstalacoesIndustriais } from "../../interface/js/servicos/ServicoInstalacoesIndustriais.js";

const responseBody = {
  data: {
    installations: [
      {
        id: 1,
        archived_at: "2026-10-23 03:06:13.746",
        access_blocked_at: "2026-10-23 03:06:13.746",
      },
      { id: 2, archived_at: null, access_blocked_at: null },
      { id: 3, archived: false, archived_at: "2026-10-23 03:06:13.746", access_blocked: false, access_blocked_at: "2026-10-23 03:06:13.746" },
    ],
    equipment_options: [],
  },
  pagination: { next_cursor: null },
  equipment_pagination: { next_cursor: null },
};

const service = new ServicoInstalacoesIndustriais({
  api: { fetch: async () => ({}) },
  state: { selectedCompanyId: 1 },
  jsonHeaders: () => ({}),
  jsonResponse: async () => responseBody,
  validarCursorPaginacao: () => null,
});

const { installations } = await service.loadIndustrialInstallations();
assert.deepEqual(
  installations.map(({ id, archived, access_blocked }) => ({ id, archived, access_blocked })),
  [
    { id: 1, archived: true, access_blocked: true },
    { id: 2, archived: false, access_blocked: false },
    { id: 3, archived: false, access_blocked: false },
  ],
);
assert.equal(service.state.industrialInstallations[0].archived, true);
console.log("Estados antigos por timestamp e estados booleanos atuais: OK");
