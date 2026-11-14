import assert from "node:assert/strict";
import test from "node:test";
import { work } from "../interface/telas/operacao/operacao.js";

const ACOES_ESTEIRA = ["run", "stop", "reverse-on", "reverse-off"];

function botoesDeEsteira(markup) {
  return [...markup.matchAll(/<button\b[^>]*data-action="(run|stop|reverse-on|reverse-off)"[^>]*>/g)]
    .map(([html, action]) => ({ action, html }));
}

function habilitadas(markup) {
  return botoesDeEsteira(markup)
    .filter(({ html }) => !/\sdisabled(?:\s|=|>)/.test(html))
    .map(({ action }) => action);
}

function textoBotao(markup, action) {
  const match = markup.match(new RegExp(`<button\\b(?=[^>]*data-action="${action}")[^>]*>(.*?)<\\/button>`, "s"));
  return match?.[1] || "";
}

function render(stateOverrides = {}, dependencias = {}) {
  const state = {
    selectedLoadingId: 42,
    activeLoadings: [{ id: 42, state: "PAUSADO", equipment_id: 3 }],
    loadingId: 42,
    loadingItems: [],
    equipmentCode: "EST-003",
    romaneio: "R-42",
    truck: "ABC1234",
    operationalState: "PAUSADO",
    detectedBags: 0,
    planned: 1,
    loaded: 0,
    userRole: "USUARIO",
    running: false,
    reversalCommand: "REVERSAO_DESATIVAR",
    commandInFlight: false,
    emergency: false,
    pendingReadings: [],
    ...stateOverrides,
  };

  return work({ state, ...dependencias });
}

function assertTodosVisiveis(markup) {
  const botoes = botoesDeEsteira(markup);
  assert.deepEqual(botoes.map(({ action }) => action), ACOES_ESTEIRA);
  assert.doesNotMatch(botoes.map(({ html }) => html).join(" "), /\shidden(?:\s|=|>)/);
}

test("mantém os quatro botões de esteira visíveis em todos os estados", () => {
  for (const operationalState of ["PREPARANDO", "PAUSADO", "CARREGANDO", "FINALIZANDO", "AGUARDANDO", "FINALIZADO", "EMERGENCIA"]) {
    assertTodosVisiveis(render({
      operationalState,
      running: operationalState === "CARREGANDO",
      emergency: operationalState === "EMERGENCIA",
    }));
  }
});

test("em movimento habilita somente parar, em sentido normal ou reverso", () => {
  for (const scenario of [
    { operationalState: "CARREGANDO", running: true, reversalCommand: "REVERSAO_DESATIVAR", label: "Desligar esteira · sentido normal" },
    { operationalState: "CARREGANDO", running: true, reversalCommand: "REVERSAO_ATIVAR", label: "Desligar esteira · sentido reversa" },
    { operationalState: "FINALIZANDO", running: false, reversalCommand: "REVERSAO_DESATIVAR", label: "Desligar esteira · sentido normal" },
    { operationalState: "FINALIZANDO", running: false, reversalCommand: "REVERSAO_ATIVAR", label: "Desligar esteira · sentido reversa" },
  ]) {
    const markup = render({
      operationalState: scenario.operationalState,
      running: scenario.running,
      reversalCommand: scenario.reversalCommand,
    });

    assertTodosVisiveis(markup);
    assert.deepEqual(habilitadas(markup), ["stop"]);
    assert.equal(textoBotao(markup, "stop"), scenario.label);
  }
});

test("início pendente habilita somente parar e emergência", () => {
  const markup = render({
    operationalState: "PREPARANDO",
    plcCommand: { command: "INICIAR_CARREGAMENTO", status: "PENDENTE" },
    commandIntent: { command: "INICIAR_CARREGAMENTO", status: "PENDENTE" },
    commandInFlight: true,
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["stop"]);
  assert.match(markup, /data-action="emergency"(?![^>]*\sdisabled)/);
});

test("FINALIZANDO mantém somente a parada habilitada", () => {
  const markup = render({ operationalState: "FINALIZANDO", running: false });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["stop"]);
});

test("CARREGANDO sem confirmação de movimento não libera comandos normais", () => {
  const markup = render({ operationalState: "CARREGANDO", running: false });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
});

test("esteira parada habilita partida e apenas a reversão aplicável", () => {
  for (const operationalState of ["PREPARANDO", "PAUSADO"]) {
    const normal = render({
      operationalState,
      reversalCommand: "REVERSAO_DESATIVAR",
    });
    assertTodosVisiveis(normal);
    assert.deepEqual(habilitadas(normal), ["run", "reverse-on"]);

    const reversa = render({
      operationalState,
      reversalCommand: "REVERSAO_ATIVAR",
    });
    assertTodosVisiveis(reversa);
    assert.deepEqual(habilitadas(reversa), ["run", "reverse-off"]);
    const run = botoesDeEsteira(reversa).find(({ action }) => action === "run").html;
    assert.match(run, /title="Confirme a reversão desligada antes de ligar a esteira para frente\./);
    assert.match(run, /data-lock-reason="Confirme a reversão desligada antes de ligar a esteira para frente\./);
  }
});

test("AGUARDANDO e FINALIZADO preservam a partida clicável com o motivo do bloqueio", () => {
  for (const operationalState of ["AGUARDANDO", "FINALIZADO"]) {
    const markup = render({ operationalState });

    assertTodosVisiveis(markup);
    assert.deepEqual(habilitadas(markup), ["run"]);
    const run = botoesDeEsteira(markup).find(({ action }) => action === "run").html;
    assert.match(run, /title=/);
    assert.match(run, /data-lock-reason=/);
  }
});

test("em emergência a partida mantém o motivo e o botão de emergência segue habilitado", () => {
  const markup = render({ operationalState: "EMERGENCIA", emergency: true });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["run"]);
  const run = botoesDeEsteira(markup).find(({ action }) => action === "run").html;
  assert.match(run, /data-lock-reason=/);
  assert.match(markup, /data-action="emergency"(?![^>]*\sdisabled)/);
});

test("botão de emergência continua habilitado sem carregamento selecionado", () => {
  const markup = render({
    selectedLoadingId: null,
    activeLoadings: [],
    loadingId: null,
    operationalState: "AGUARDANDO",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(markup, /data-action="emergency"(?![^>]*\sdisabled)/);
  assert.match(markup, /title="Nenhum carregamento selecionado"/);
});

test("CLP indisponível bloqueia comandos normais e mantém emergência habilitada", () => {
  const markup = render({}, {
    clpDisponivel: () => false,
    mensagemClpIndisponivel: () => "CLP indisponível para novos comandos.",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(markup, /data-action="emergency"(?![^>]*\sdisabled)/);
  assert.match(botoesDeEsteira(markup).find(({ action }) => action === "run").html, /CLP indisponível para novos comandos\./);
});

test("gateway sem liberação bloqueia comandos normais e mantém emergência habilitada", () => {
  const markup = render({}, {
    clpDisponivel: () => true,
    comandosMaquinaDisponiveis: () => false,
    mensagemComandosMaquinaIndisponiveis: () => "Gateway sem liberação dos comandos.",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(markup, /data-action="emergency"(?![^>]*\sdisabled)/);
  assert.match(botoesDeEsteira(markup).find(({ action }) => action === "run").html, /Gateway sem liberação dos comandos\./);
});
