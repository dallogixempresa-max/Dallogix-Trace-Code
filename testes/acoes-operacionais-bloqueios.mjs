import assert from "node:assert/strict";
import test from "node:test";
import { criarTratadorAcoesOperacionais } from "../interface/js/controladores/acoes-operacionais.js";

function preparar({ operationalState = "PAUSADO", loadingId = 42, page = "work" } = {}) {
  const state = { operationalState, loadingId, commandFeedback: "" };
  const commands = [];
  const messages = [];
  let renders = 0;
  const tratar = criarTratadorAcoesOperacionais({
    store: { state },
    render: () => { renders += 1; },
    paginaAtual: () => page,
    notificarForaDaOperacao: (message, type) => messages.push({ message, type }),
    executarComandoDeOperacao: (_node, action) => {
      commands.push(action);
      return "enviado";
    },
  });

  return {
    state,
    commands,
    messages,
    get renders() { return renders; },
    tratar,
  };
}

test("Ligar esteira em AGUARDANDO ou EMERGENCIA mostra o motivo sem enviar comando", async () => {
  for (const operationalState of ["AGUARDANDO", "EMERGENCIA"]) {
    const harness = preparar({ operationalState });
    const reason = `Bloqueio em ${operationalState}`;

    const result = await harness.tratar("run", { dataset: { actionNotice: reason } });

    assert.equal(result, true);
    assert.deepEqual(harness.commands, []);
    assert.equal(harness.state.commandFeedback, reason);
    assert.equal(harness.renders, 1);
  }
});

test("FINALIZADO também não envia início mesmo se a ação for disparada fora do botão desabilitado", async () => {
  const harness = preparar({ operationalState: "FINALIZADO" });

  const result = await harness.tratar("run", { dataset: {} });

  assert.equal(result, true);
  assert.deepEqual(harness.commands, []);
  assert.match(harness.state.commandFeedback, /já foi finalizado/);
});

test("Emergência sem carregamento selecionado explica o limite e não envia comando sem alvo", async () => {
  const harness = preparar({ operationalState: "AGUARDANDO", loadingId: null });

  const result = await harness.tratar("emergency", { dataset: {} });

  assert.equal(result, true);
  assert.deepEqual(harness.commands, []);
  assert.match(harness.state.commandFeedback, /emergência física/i);
  assert.equal(harness.renders, 1);
});

test("Emergência em carregamento finalizado orienta o operador sem enviar comando recusado", async () => {
  const harness = preparar({ operationalState: "FINALIZADO" });

  const result = await harness.tratar("emergency", { dataset: {} });

  assert.equal(result, true);
  assert.deepEqual(harness.commands, []);
  assert.match(harness.state.commandFeedback, /já foi finalizado/i);
  assert.match(harness.state.commandFeedback, /emergência física/i);
});

test("comandos permitidos continuam sendo encaminhados", async () => {
  const harness = preparar({ operationalState: "PAUSADO" });

  const result = await harness.tratar("run", { dataset: {} });

  assert.equal(result, "enviado");
  assert.deepEqual(harness.commands, ["run"]);
});

test("emergência permanece encaminhada em todos os estados ativos mesmo com outro comando em voo", async () => {
  for (const operationalState of ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA"]) {
    const harness = preparar({ operationalState });
    harness.state.commandInFlight = true;

    assert.equal(await harness.tratar("emergency", { dataset: {} }), "enviado");
    assert.deepEqual(harness.commands, ["emergency"]);
  }
});

test("partida em reversão e parada durante início pendente preservam o encaminhamento", async () => {
  const harness = preparar();
  harness.state.reversalCommand = "REVERSAO_ATIVAR";
  await harness.tratar("run", { dataset: { actionNotice: "Confirme a reversão desligada antes de ligar a esteira para frente." } });
  harness.state.commandInFlight = true;
  await harness.tratar("stop", { dataset: {} });

  assert.deepEqual(harness.commands, ["run", "stop"]);
});
