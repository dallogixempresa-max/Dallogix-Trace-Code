import { dataHora, hora, instanteMs } from "./formato.js?v=202611260002";
import { agora as agoraDoRelogio } from "./relogio.js?v=202611260002";

// Rota TCP do leitor: o leitor entrega cada código ao worker do PC, que o
// associa à passagem e grava no Trace sem passar pelo navegador. O heartbeat
// do worker (status_dispositivos.php → tcp_route) conta o que não pôde ser
// associado; a tela soma isso ao que a rota HID conta localmente.

// Mesmos textos que a rota HID mostra a partir da resposta do POST (aplicacao.js).
export const AVISO_PRODUTO_INCORRETO =
  "Produto fora do romaneio. Remova a unidade e confirme a parada física antes de continuar.";
export const AVISO_CODIGO_NAO_CADASTRADO =
  "Código válido não cadastrado no catálogo. A esteira foi pausada para conferência; o saco não foi contado na quantidade prevista.";
export const AVISO_EXCESSO =
  "Quantidade excedida. O carregamento foi pausado. Remova a unidade e confirme.";
export const AVISO_COTA_PRODUTO =
  "Quantidade prevista deste produto atingida. Continue somente com o próximo item do romaneio.";

const MAXIMO_LEITURAS_ENVIADAS_NESTA_TELA = 50;
const MAXIMO_COTAS_AVISADAS_NESTA_TELA = 50;
// Sem consulta por mais tempo que isso (outra tela, aba parada), a referência
// é refeita sem avisar: o que chegou nesse intervalo já é histórico.
const REFERENCIA_LEITURAS_VENCIDA_MS = 30000;
// Leitura do servidor mais antiga que isso (lote sincronizado depois, por
// exemplo na Central) não vira aviso imediato.
const IDADE_MAXIMA_AVISO_LEITURA_MS = 30000;
const VINTE_HORAS_MS = 20 * 60 * 60 * 1000;

function inteiroNaoNegativo(value) {
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0;
}

function plural(quantidade, singular, pluralTexto) {
  return `${quantidade} ${quantidade === 1 ? singular : pluralTexto}`;
}

function momento(value, agoraMs) {
  const instante = instanteMs(value);
  if (instante === null) return "";
  return Math.abs(agoraMs - instante) < VINTE_HORAS_MS ? hora(value) : dataHora(value);
}

/** Rota TCP informada pelo leitor da Dala do carregamento selecionado. */
export function rotaTcpDoLeitor(state) {
  const equipmentId = Number(state?.equipmentId) || null;
  if (!equipmentId) return null;
  const devices = state?.deviceStatusesUpdatedAt
    ? state.deviceStatuses || []
    : state?.monitoring?.dispositivos || [];
  const scanner = (Array.isArray(devices) ? devices : []).find(
    (device) => device?.device_type === "SCANNER" && Number(device.equipment_id) === equipmentId,
  );
  const route = scanner?.tcp_route;
  return route && typeof route === "object" && !Array.isArray(route) ? route : null;
}

/** Códigos que o worker recebeu para este carregamento sem passagem associada. */
export function semPassagemDaRotaTcp(state) {
  const loadingId = Number(state?.loadingId) || null;
  const entries = rotaTcpDoLeitor(state)?.unassociated_by_loading;
  if (!loadingId || !Array.isArray(entries)) return { count: 0, last: null };
  const entry = entries.find((item) => Number(item?.loading_id) === loadingId);
  if (!entry) return { count: 0, last: null };
  const last = entry.last && typeof entry.last === "object" ? entry.last : null;
  return { count: inteiroNaoNegativo(entry.count), last };
}

/**
 * Contador da tela: rota HID (local) + rota TCP (worker), fontes disjuntas.
 * O count do worker já exclui o código recebido fora de CARREGANDO, como a
 * rota HID descarta a captura com a esteira parada.
 */
export function totalLeiturasSemPassagem(state) {
  return inteiroNaoNegativo(state?.scannerUnmatchedCount) + semPassagemDaRotaTcp(state).count;
}

/**
 * Leitura exibida no visor. O último código sem passagem do worker aparece
 * como SEM_PASSAGEM, igual à rota HID, enquanto for mais recente que a
 * última leitura registrada ou capturada nesta tela. Como a rota HID, que
 * descarta a captura com a esteira parada, o visor só mostra o código
 * recebido com o carregamento em CARREGANDO (loading_state do worker).
 */
export function leituraDoVisor(state) {
  const atual = state?.latestScannerReading || null;
  const { last } = semPassagemDaRotaTcp(state);
  if (last?.loading_state !== "CARREGANDO") return atual;
  const recebidaEm = instanteMs(last?.received_at);
  if (recebidaEm === null) return atual;
  const atualEm = instanteMs(atual?.readAt);
  if (atualEm !== null && atualEm >= recebidaEm) return atual;
  const barcode = String(last.barcode || "");
  return {
    barcode,
    result: "SEM_PASSAGEM",
    readAt: String(last.received_at),
    value: last.no_read === true || !barcode ? "Falha na leitura (sem código)" : barcode,
  };
}

/**
 * Texto e alerta da rota TCP no cartão do leitor em Configurações.
 * leitorRespondeNaRede: a sondagem de rede recente teve resposta do leitor.
 * Com o Docker Desktop a conexão vem sempre do gateway e pode seguir aberta
 * depois que o leitor some da rede; "conectada" exige as duas coisas.
 */
export function descreverRotaTcp(route, agoraMs = Date.now(), { leitorRespondeNaRede = false } = {}) {
  if (!route || typeof route !== "object" || Array.isArray(route)) return null;
  // Heartbeat vencido (servidor marca stale): a rota é a última informada pelo
  // worker, não o estado atual.
  if (route.stale === true) {
    const desde = momento(route.stale_since, agoraMs);
    return {
      texto: desde
        ? `Sem informação recente do worker do leitor (desde ${desde})`
        : "Sem informação recente do worker do leitor",
      atencao: false,
    };
  }
  const partes = [];
  if (route.connected === true) {
    partes.push(leitorRespondeNaRede === true
      ? "Rota TCP conectada"
      : "Rota TCP aberta, mas o leitor não responde na rede");
  } else if (!route.last_connected_at && !route.last_disconnected_at) {
    partes.push("Rota TCP aguardando a primeira leitura");
  } else {
    const desde = momento(route.last_disconnected_at, agoraMs);
    partes.push(desde ? `Rota TCP sem conexão desde ${desde}` : "Rota TCP sem conexão");
  }
  const ultimoCodigo = momento(route.last_frame_at, agoraMs);
  if (ultimoCodigo) partes.push(`último código recebido às ${ultimoCodigo}`);
  const semPassagem = inteiroNaoNegativo(route.scans_unassociated);
  if (semPassagem > 0) partes.push(plural(semPassagem, "código sem passagem", "códigos sem passagem"));
  // failed-scans guarda o histórico das leituras não registradas: a quantidade
  // é informação, não alerta (senão o cartão ficaria em atenção para sempre).
  const retidas = inteiroNaoNegativo(route.failed_scans_pending);
  if (retidas > 0) partes.push(plural(retidas, "leitura retida no PC para conferência", "leituras retidas no PC para conferência"));
  // Só o erro recente (o servidor descarta o de mais de 10 min) pede atenção.
  const erro = typeof route.scan_error === "string" ? route.scan_error.trim() : "";
  if (erro) partes.push(`Erro: ${erro}`);
  return { texto: partes.join(" · "), atencao: Boolean(erro) };
}

/**
 * Avisos imediatos da última leitura gravada no servidor, como a rota HID
 * mostra. A cota do produto não depende só da última leitura: duas leituras
 * TCP entre consultas podem esconder a que atingiu a quantidade prevista, por
 * isso ela é avaliada por item em produtosQueAtingiramACota.
 */
export function avisosDaLeituraDoServidor(loading) {
  const result = String(loading?.last_scanner_result || "");
  const operatorAlert = String(loading?.operator_alert || "").trim();
  const avisos = [];
  if (result === "PRODUTO_INCORRETO") {
    const naoCadastrado = loading.last_scanner_product_id == null && !Number(loading.last_scanner_catalog_product);
    avisos.push({
      mensagem: naoCadastrado ? AVISO_CODIGO_NAO_CADASTRADO : (operatorAlert || AVISO_PRODUTO_INCORRETO),
      tipo: "erro",
    });
  }
  if (result === "EXCESSO") {
    avisos.push({ mensagem: operatorAlert || AVISO_EXCESSO, tipo: "erro" });
  }
  return avisos;
}

/** Quantidade prevista e contada (válidas + sem leitura do produto) por produto. */
export function contagemPorProduto(items) {
  const contagem = new Map();
  for (const item of Array.isArray(items) ? items : []) {
    const productId = Number(item?.product_id);
    if (!Number.isSafeInteger(productId) || productId < 1) continue;
    const atual = contagem.get(productId) || { planned: 0, counted: 0 };
    atual.planned += Number(item?.planned_quantity) || 0;
    atual.counted += (Number(item?.loaded_quantity) || 0) + (Number(item?.no_read_quantity) || 0);
    contagem.set(productId, atual);
  }
  return contagem;
}

/**
 * Produtos que passaram de abaixo da quantidade prevista para igual ou acima
 * dela entre duas consultas do mesmo carregamento. Produto ausente na
 * consulta anterior não conta como travessia.
 */
export function produtosQueAtingiramACota(anterior, atual) {
  const produtos = [];
  for (const [productId, { planned, counted }] of atual) {
    const antes = anterior?.get(productId);
    if (planned > 0 && antes && antes.counted < planned && counted >= planned) produtos.push(productId);
  }
  return produtos;
}

/** Guarda o id devolvido pelo POST da rota HID para não avisar a leitura duas vezes. */
export function registrarLeituraEnviadaNestaTela(state, readingId) {
  const id = Number(readingId);
  if (!state || !Number.isSafeInteger(id) || id < 1) return;
  const ids = Array.isArray(state.scannerReadingIdsPostedHere) ? state.scannerReadingIdsPostedHere : [];
  if (ids.includes(id)) return;
  state.scannerReadingIdsPostedHere = [...ids, id].slice(-MAXIMO_LEITURAS_ENVIADAS_NESTA_TELA);
}

function chaveCota(loadingId, productId) {
  return `${Number(loadingId)}:${Number(productId)}`;
}

/**
 * A resposta do POST da rota HID disse que esta leitura atingiu a cota do
 * produto e a tela já avisou: a travessia vista depois na atualização ao vivo
 * não repete o aviso.
 */
export function registrarCotaAvisadaNestaTela(state, loadingId, productId) {
  const loading = Number(loadingId);
  const product = Number(productId);
  if (!state || !Number.isSafeInteger(loading) || loading < 1 || !Number.isSafeInteger(product) || product < 1) return;
  const chave = chaveCota(loading, product);
  const chaves = Array.isArray(state.scannerQuotaWarnedHere) ? state.scannerQuotaWarnedHere : [];
  if (chaves.includes(chave)) return;
  state.scannerQuotaWarnedHere = [...chaves, chave].slice(-MAXIMO_COTAS_AVISADAS_NESTA_TELA);
}

function cotaAvisadaNestaTela(state, loadingId, productId) {
  const chaves = Array.isArray(state?.scannerQuotaWarnedHere) ? state.scannerQuotaWarnedHere : [];
  return chaves.includes(chaveCota(loadingId, productId));
}

/**
 * Decide os avisos do estado do servidor entre duas consultas. A primeira
 * carga (ou troca de carregamento) só define a referência; leituras enviadas
 * por esta tela não repetem o aviso que a resposta do POST já deu; leitura
 * com mais de 30 s (lote sincronizado depois) não vira aviso.
 * agora: relógio da tela, o mesmo que compara os horários do servidor.
 */
export function criarAvaliadorLeiturasDoServidor({ agora = () => agoraDoRelogio().getTime() } = {}) {
  let referencia = null;
  return {
    reiniciar() {
      referencia = null;
    },
    avaliar(state, loading, selectionChanged = false) {
      const loadingId = Number(loading?.id) || null;
      const readingId = Number(loading?.last_scanner_reading_id) || null;
      const instante = agora();
      if (!loadingId) {
        referencia = null;
        return [];
      }
      const contagem = contagemPorProduto(loading.items);
      if (selectionChanged || !referencia || referencia.loadingId !== loadingId
        || instante - referencia.conferidaEm > REFERENCIA_LEITURAS_VENCIDA_MS) {
        referencia = { loadingId, readingId, contagem, cotasAvisadas: new Set(), conferidaEm: instante };
        return [];
      }
      referencia.conferidaEm = instante;
      // Com um POST da rota HID em andamento, a leitura nova (e a cota que ela
      // atingiu) pode ser a dele: a decisão espera a resposta registrar o id,
      // sem mover a referência.
      if ((Number(state?.scannerReadingsInFlight) || 0) > 0) return [];
      const leituraNova = readingId !== null && (referencia.readingId === null || readingId > referencia.readingId);
      if (leituraNova) referencia.readingId = readingId;
      const cotas = produtosQueAtingiramACota(referencia.contagem, contagem)
        .filter((productId) => !referencia.cotasAvisadas.has(productId));
      referencia.contagem = contagem;
      // Uma vez por item, mesmo quando o aviso fica com a resposta do POST.
      cotas.forEach((productId) => referencia.cotasAvisadas.add(productId));

      const lidaEm = instanteMs(loading?.last_scanner_read_at);
      if (lidaEm === null || instante - lidaEm > IDADE_MAXIMA_AVISO_LEITURA_MS) return [];

      const avisos = [];
      // A tela mostra um aviso por vez: a cota vem antes para que o erro de
      // produto incorreto ou excesso, quando houver, fique visível.
      if (cotas.some((productId) => !cotaAvisadaNestaTela(state, loadingId, productId))) {
        avisos.push({ mensagem: AVISO_COTA_PRODUTO, tipo: "aviso" });
      }
      const enviadas = Array.isArray(state?.scannerReadingIdsPostedHere) ? state.scannerReadingIdsPostedHere : [];
      if (leituraNova && !enviadas.includes(readingId)) avisos.push(...avisosDaLeituraDoServidor(loading));
      return avisos;
    },
  };
}
