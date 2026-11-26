import assert from "node:assert/strict";
import test from "node:test";
import { work } from "../interface/telas/operacao/operacao.js";

const ACOES_ESTEIRA = ["run", "stop"];

function botoesDeEsteira(markup) {
  return [...markup.matchAll(/<button\b[^>]*data-action="(run|stop|reverse-on|reverse-off)"[^>]*>/g)]
    .map(([html, action]) => ({ action, html }));
}

function habilitadas(markup) {
  return botoesDeEsteira(markup)
    .filter(({ html }) => !/\sdisabled(?:\s|=|>)/.test(html))
    .map(({ action }) => action);
}

function botao(markup, action) {
  const match = markup.match(new RegExp(`<button\\b(?=[^>]*data-action="${action}")[^>]*>`));
  return match?.[0] || "";
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

test("mantém os dois comandos do operador visíveis em todos os estados", () => {
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

test("início pendente habilita somente parar", () => {
  const markup = render({
    operationalState: "PREPARANDO",
    plcCommand: { command: "INICIAR_CARREGAMENTO", status: "PENDENTE" },
    commandIntent: { command: "INICIAR_CARREGAMENTO", status: "PENDENTE" },
    commandInFlight: true,
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["stop"]);
  assert.doesNotMatch(markup, /data-action="emergency"/);
});

test("FINALIZANDO mantém somente a parada habilitada", () => {
  const markup = render({ operationalState: "FINALIZANDO", running: false });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["stop"]);
});

test("CARREGANDO sem confirmação de movimento bloqueia a parada com motivo claro", () => {
  const markup = render({ operationalState: "CARREGANDO", running: false });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(botao(markup, "stop"), /title="Aguardando confirmação de movimento para habilitar a parada\./);
  assert.match(botao(markup, "stop"), /data-block-reason=/);
  assert.doesNotMatch(botao(markup, "stop"), /data-action-notice=/);
});

test("estado PREPARANDO com indicador de movimento não libera comandos normais", () => {
  const markup = render({ operationalState: "PREPARANDO", running: true });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(botao(markup, "stop"), /data-block-reason=/);
});

test("esteira parada oferece partida ao operador e indica o estado da reversão", () => {
  for (const operationalState of ["PREPARANDO", "PAUSADO"]) {
    const normal = render({
      operationalState,
      reversalCommand: "REVERSAO_DESATIVAR",
    });
    assertTodosVisiveis(normal);
    assert.deepEqual(habilitadas(normal), ["run"]);

    const reversa = render({
      operationalState,
      reversalCommand: "REVERSAO_ATIVAR",
    });
    assertTodosVisiveis(reversa);
    assert.deepEqual(habilitadas(reversa), ["run"]);
    const run = botao(reversa, "run");
    assert.match(run, /title="Confirme a reversão desligada antes de ligar a esteira para frente\./);
    assert.match(run, /data-action-notice="Confirme a reversão desligada antes de ligar a esteira para frente\./);
    assert.doesNotMatch(run, /data-block-reason=/);
  }
});

test("motivo de parada desabilita Ligar esteira sem ocultar o motivo", () => {
  const markup = render({ operationalState: "PREPARANDO", stopReason: "COTA_ROMANEIO_ATINGIDA" });
  const run = botao(markup, "run");

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(run, /\sdisabled(?:\s|=|>)/);
  assert.match(run, /data-block-reason="Confirme a parada física e finalize o romaneio/);
});

test("meta atingida bloqueia nova partida mesmo sem stopReason", () => {
  const markup = render({ operationalState: "PAUSADO", planned: 1, detectedBags: 1 });
  const run = botao(markup, "run");

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.match(run, /\sdisabled(?:\s|=|>)/);
  assert.match(run, /data-block-reason="A quantidade prevista do romaneio foi atingida/);
});

test("estado desconhecido da reversão orienta o operador na partida", () => {
  const markup = render({ operationalState: "PAUSADO", reversalCommand: "DESCONHECIDO" });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["run"]);
  assert.match(botao(markup, "run"), /data-action-notice="Confirme a reversão desligada/);
});

test("reversão pendente bloqueia novos comandos e explica a espera", () => {
  for (const status of ["PENDENTE", "PROCESSANDO"]) {
    const markup = render({
      operationalState: "PAUSADO",
      plcCommand: { command: "REVERSAO_ATIVAR", status },
      commandIntent: { command: "REVERSAO_ATIVAR", status },
    });

    assertTodosVisiveis(markup);
    assert.deepEqual(habilitadas(markup), []);
    assert.match(botao(markup, "run"), /data-block-reason=/);
    assert.match(botao(markup, "run"), /title="Aguarde/);
  }
});

test("comando em voo sem estado detalhado bloqueia controles com motivo", () => {
  const markup = render({ commandInFlight: true });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  for (const action of ACOES_ESTEIRA) {
    assert.match(botao(markup, action), /data-block-reason=/);
  }
});

test("AGUARDANDO preserva a partida clicável com aviso", () => {
  const markup = render({ operationalState: "AGUARDANDO" });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["run"]);
  const run = botao(markup, "run");
  assert.match(run, /title=/);
  assert.match(run, /data-action-notice=/);
  assert.doesNotMatch(run, /data-block-reason=/);
});

test("FINALIZADO bloqueia a partida e preserva o motivo", () => {
  const markup = render({ operationalState: "FINALIZADO" });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  const run = botao(markup, "run");
  assert.match(run, /\sdisabled(?:\s|=|>)/);
  assert.match(run, /title="Este carregamento já foi finalizado\./);
  assert.match(run, /data-block-reason="Este carregamento já foi finalizado\./);
});

test("registro histórico interrompido não oferece comando de emergência", () => {
  const markup = render({ operationalState: "EMERGENCIA", emergency: true });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), ["run"]);
  const run = botao(markup, "run");
  assert.match(run, /data-action-notice=/);
  assert.doesNotMatch(run, /data-block-reason=/);
  assert.doesNotMatch(markup, /data-action="emergency"/);
});

test("sem carregamento não oferece comando de emergência", () => {
  const markup = render({
    selectedLoadingId: null,
    activeLoadings: [],
    loadingId: null,
    operationalState: "AGUARDANDO",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.doesNotMatch(markup, /data-action="emergency"/);
  assert.match(botao(markup, "run"), /data-block-reason="Nenhum carregamento selecionado\."/);
});

test("CLP indisponível bloqueia comandos normais e não oferece emergência", () => {
  const markup = render({}, {
    clpDisponivel: () => false,
    mensagemClpIndisponivel: () => "CLP indisponível para novos comandos.",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.doesNotMatch(markup, /data-action="emergency"/);
  assert.match(botao(markup, "run"), /CLP indisponível para novos comandos\./);
  assert.match(botao(markup, "run"), /data-block-reason=/);
  assert.doesNotMatch(botao(markup, "run"), /data-action-notice=/);
});

test("gateway sem liberação bloqueia comandos normais e não oferece emergência", () => {
  const markup = render({}, {
    clpDisponivel: () => true,
    comandosMaquinaDisponiveis: () => false,
    mensagemComandosMaquinaIndisponiveis: () => "Gateway sem liberação dos comandos.",
  });

  assertTodosVisiveis(markup);
  assert.deepEqual(habilitadas(markup), []);
  assert.doesNotMatch(markup, /data-action="emergency"/);
  assert.match(botao(markup, "run"), /Gateway sem liberação dos comandos\./);
  assert.match(botao(markup, "run"), /data-block-reason=/);
  assert.doesNotMatch(botao(markup, "run"), /data-action-notice=/);
});

test("perfil de leitura acompanha a operação sem comandos", () => {
  const markup = render({ userRole: "LEITURA" });

  assert.deepEqual(botoesDeEsteira(markup), []);
  assert.match(markup, /Sacas detectadas pelo sensor/);
});

test("admin e supervisor acompanham os produtos sem comandos da esteira", () => {
  for (const userRole of ["ADMIN_EMPRESA", "SUPERVISOR"]) {
    const markup = render({ userRole, loadingItems: [{ name: "Produto do romaneio" }] });
    assert.deepEqual(botoesDeEsteira(markup), []);
    assert.match(markup, /Produto do romaneio/);
  }
});


test("uma passagem ausente permanece sinalizada quando chega um código válido", () => {
  const markup = render({
    operationalState: "CARREGANDO",
    scannerUnmatchedCount: 1,
    latestScannerReading: { result: "VALIDO", value: "7898538751531" },
  });
  assert.match(markup, /data-live="scanner-unmatched-notice" role="status">Leituras sem passagem neste carregamento/);
  assert.match(markup, /data-live="scanner-unmatched-count">1<\/strong>/);
  assert.match(markup, /7898538751531/);
});
