import { apresentacaoConectividadePc, apresentacaoStatusDispositivo, renderStatusDalaBinario, resumoStatusDispositivos } from "../../telas/configuracoes/configuracoes.js?v=202610280001";
import { operationStructureSignature, renderDalaStatuses } from "./operacao.js?v=202610290001";
import { numero, relativo, dataHora } from "../funcoes/formato.js?v=202609201000";
import { el } from "../funcoes/html.js";
import { refreshCompanyStatusCards } from "../funcoes/view.js?v=202610290001";

/** Mantém as regiões de estado sincronizadas sem recriar formulários ou a tela toda. */
export function createLiveViewController({
  store,
  getCurrentPage,
  screens,
  getScreenRoot = () => el("#screen-root"),
  renderScreen,
  bindActions,
  getCompanyViewSignature,
  setCompanyViewSignature,
}) {
  let relativeTimeTimer = null;

  function companyStatusViewSignature() {
    const companies = Array.isArray(store.state.companies) ? store.state.companies : [];
    return JSON.stringify([
      Boolean(store.state.companyStatusStale),
      companies.map((company) => [
        company.id,
        company.archived,
        company.total_machines,
        company.machines_online,
        company.industrial_pc_status,
        company.total_users,
        company.active_users,
        company.license_status,
      ]),
    ]);
  }

  function workStructureSignature() {
    return operationStructureSignature(store.state, store.clpDisponivel());
  }

  function refreshWorkLiveView() {
    const detectedBags = Number(store.state.detectedBags) || 0;
    const validReadings = Number(store.state.loaded) || 0;
    const planned = Number(store.state.planned) || 0;
    const remaining = Math.max(0, planned - detectedBags);
    const percent = planned > 0 ? Math.min(100, Math.round((detectedBags / planned) * 100)) : 0;
    const values = {
      planned: numero(planned),
      detected: numero(detectedBags),
      loaded: numero(validReadings),
      "remaining-label": planned <= 0
        ? "Quantidade prevista não informada"
        : remaining === 0
          ? `Quantidade física atingida · ${numero(validReadings)} códigos válidos`
          : `Falta${remaining === 1 ? "" : "m"} ${numero(remaining)} saca${remaining === 1 ? "" : "s"} para a quantidade prevista`,
      "progress-percent": `${percent}%`,
      "scanner-value": store.state.latestScannerReading?.value || "Aguardando leitura do Keyence…",
    };
    Object.entries(values).forEach(([key, value]) => {
      document.querySelectorAll(`[data-live="${key}"]`).forEach((node) => {
        node.textContent = String(value);
      });
    });
    const scannerValue = document.querySelector('[data-live="scanner-value"]');
    scannerValue?.classList.toggle(
      "is-error",
      store.state.latestScannerReading?.result === "SEM_LEITURA",
    );
    const progressBar = document.querySelector('[data-live="progress-bar"]');
    if (progressBar) {
      progressBar.style.width = `${percent}%`;
      progressBar.parentElement?.setAttribute("aria-valuenow", String(percent));
    }
    const endNotice = document.querySelector('[data-live="end-notice"]');
    if (endNotice) {
      const threshold = planned > 0 ? Math.max(1, Math.ceil(planned * 0.1)) : 0;
      const nearEnd = planned > 0 && detectedBags > 0 && remaining > 0 && remaining <= threshold;
      const complete = planned > 0 && detectedBags >= planned;
      const excess = planned > 0 && detectedBags > planned;
      endNotice.textContent = excess
        ? `Atenção: ${numero(detectedBags - planned)} sacas passaram do total previsto.`
        : complete
          ? "Quantidade física prevista atingida. O leitor continua associando os códigos das sacas detectadas."
          : nearEnd
            ? "Atenção: o carregamento está próximo da quantidade prevista."
            : "";
      endNotice.hidden = !complete && !nearEnd && !excess;
      endNotice.classList.toggle("complete", complete && !excess);
      endNotice.classList.toggle("near-end", nearEnd && !complete);
      endNotice.classList.toggle("excess", excess);
    }
  }

  function refreshSettingsStatusView() {
    const devices = store.state.deviceStatuses || [];
    const presentations = [];
    document.querySelectorAll("[data-device-status-card]").forEach((card) => {
      const type = card.dataset.deviceStatusCard;
      if (!["SENSOR", "SCANNER", "CLP", "SERVER"].includes(type)) {
        card.remove();
        return;
      }
      const equipmentId = Number(card.dataset.liveStatusEquipment) || null;
      const device = type === "SERVER"
        ? null
        : devices.find((item) => item.device_type === type &&
          (!equipmentId || Number(item.equipment_id) === equipmentId));
      const value = type === "SERVER"
        ? (store.state.serverStatus || "DESCONHECIDO")
        : (device?.status || "NAO_REGISTRADO");
      const presentation = apresentacaoStatusDispositivo(value, type);
      presentations.push(presentation);
      card.className = `settings-device-status-card ${presentation.tone}`;
      const status = card.querySelector("[data-live-status]");
      if (status) {
        status.textContent = presentation.label;
        status.className = `status-value status-${presentation.tone}`;
      }
      const detail = card.querySelector("[data-live-status-detail]");
      if (detail) detail.textContent = presentation.detail;
    });

    const { online: onlineCount, attention: attentionCount, unmonitored: unmonitoredCount } =
      resumoStatusDispositivos(presentations);
    const onlineSummary = document.querySelector('[data-device-summary="online-count"]');
    const attentionSummary = document.querySelector('[data-device-summary="attention-count"]');
    const unmonitoredSummary = document.querySelector('[data-device-summary="unmonitored-count"]');
    const attentionLabel = document.querySelector('[data-device-summary="attention-label"]');
    if (onlineSummary) onlineSummary.textContent = String(onlineCount);
    if (attentionSummary) attentionSummary.textContent = String(attentionCount);
    if (unmonitoredSummary) unmonitoredSummary.textContent = String(unmonitoredCount);
    if (attentionLabel) attentionLabel.textContent = "sem sinal ou com falha";

    const [pcTone, pcLabel] = apresentacaoConectividadePc(store.state.syncStatus);
    const updateConnectivity = (key, tone, label) => {
      const row = document.querySelector(`[data-settings-connectivity="${key}"]`);
      const dot = row?.querySelector("[data-settings-connectivity-dot]");
      const text = row?.querySelector("[data-settings-connectivity-label]");
      if (dot) dot.className = `status-dot ${tone}`;
      if (text) text.textContent = label;
    };
    updateConnectivity("pc", pcTone, pcLabel);

    const configuredDalas = store.state.configuration?.dalas || [];
    const dalaStatuses = store.state.dalaStatuses || [];
    const onlineDalas = dalaStatuses.filter((dala) => String(dala.status).toUpperCase() === "ONLINE").length;
    const offlineDalas = dalaStatuses.filter((dala) => ["OFFLINE", "ERRO"].includes(String(dala.status).toUpperCase())).length;
    const unknownDalas = Math.max(0, configuredDalas.length - onlineDalas - offlineDalas);
    const dalaLabel = configuredDalas.length === 0
      ? "Dalas não cadastradas"
      : onlineDalas === configuredDalas.length
        ? "Dalas online"
        : offlineDalas === configuredDalas.length
          ? "Dalas sem comunicação"
          : onlineDalas > 0
            ? "Dalas parcialmente online"
            : "Dalas sem status";
    const dalaTone = configuredDalas.length === 0 || unknownDalas > 0 || (onlineDalas > 0 && offlineDalas > 0)
      ? "unknown"
      : offlineDalas > 0
        ? "offline"
        : "online";
    updateConnectivity("dalas", dalaTone, dalaLabel);

    const statusByEquipment = new Map(dalaStatuses.map((status) => [String(status.equipment_id), status]));
    document.querySelectorAll(".dala-status[data-equipment-id]").forEach((cell) => {
      const status = statusByEquipment.get(String(cell.dataset.equipmentId));
      cell.innerHTML = renderStatusDalaBinario(status);
    });
  }

  function statusRefreshMessage(staleForMs = 0) {
    const seconds = Math.max(1, Math.floor(Number(staleForMs) / 1000));
    return `Dados desatualizados há ${seconds} s. Exibindo a última informação confirmada.`;
  }

  function refreshStatusRefreshFeedback(root, { stale = false, staleForMs = 0 } = {}) {
    let feedback = root.querySelector("[data-status-refresh-feedback]");
    if (!feedback && stale) {
      feedback = document.createElement("p");
      feedback.className = "form-feedback status-refresh-feedback";
      feedback.dataset.statusRefreshFeedback = "1";
      feedback.setAttribute("role", "status");
      feedback.setAttribute("aria-live", "polite");
      root.prepend(feedback);
    }
    if (!feedback) return;
    feedback.textContent = stale ? statusRefreshMessage(staleForMs) : "";
    feedback.hidden = !stale;
  }

  function refreshIndustrialInstallationStatuses() {
    const installations = new Map(
      (store.state.industrialInstallations || []).map((item) => [String(item.id), item]),
    );
    const lastSeenByInstallation = new Map();
    document.querySelectorAll("[data-installation-last-seen]").forEach((node) => {
      const id = node.dataset.installationLastSeen;
      if (!lastSeenByInstallation.has(id)) lastSeenByInstallation.set(id, node);
    });
    const labels = {
      ONLINE: ["Online", "online"],
      OFFLINE: ["Offline", "offline"],
      ERRO: ["Com erro", "error"],
      DESCONHECIDO: ["Sem sinal", "unknown"],
    };
    document.querySelectorAll("[data-installation-status]").forEach((node) => {
      const installation = installations.get(node.dataset.installationStatus);
      if (!installation) return;
      const status = String(installation.status || "DESCONHECIDO").toUpperCase();
      let [label, tone] = labels[status] || labels.DESCONHECIDO;
      if (!installation.activated && !installation.activated_at) [label, tone] = ["Pendente de ativação", "pending"];
      if (installation.access_blocked) [label, tone] = [installation.activated ? "Acesso bloqueado" : "Ativação bloqueada", "blocked"];
      if (installation.archived) [label, tone] = ["Arquivado", "archived"];
      node.className = `industrial-pc-status is-${tone}`;
      node.textContent = label;
      const card = node.closest(".industrial-pc-row");
      if (card) {
        card.classList.remove("is-online", "is-offline", "is-error", "is-unknown", "is-pending", "is-blocked", "is-archived");
        card.classList.add(`is-${tone}`);
      }
      const lastSeen = lastSeenByInstallation.get(String(installation.id));
      if (lastSeen) lastSeen.textContent = installation.last_seen_at ? dataHora(installation.last_seen_at) : "Ainda não se conectou";
    });
  }

  function refreshRelativeTimes() {
    document.querySelectorAll("[data-relative-time]").forEach((node) => {
      const value = node.dataset.relativeTime || "";
      const text = relativo(value);
      if (node.dataset.relativeRendered === text) return;
      node.dataset.relativeRendered = text;
      const icon = node.querySelector(".status-dot");
      if (icon) node.replaceChildren(icon, document.createTextNode(text));
      else node.textContent = text;
    });
  }

  function installRelativeTimeRefresh() {
    if (relativeTimeTimer) window.clearInterval(relativeTimeTimer);
    refreshRelativeTimes();
    // Cinco segundos evitam recalcular layout continuamente durante a rolagem no quiosque.
    relativeTimeTimer = window.setInterval(refreshRelativeTimes, 5000);
  }

  function refreshLiveStatusView(page, freshness = {}) {
    if (getCurrentPage() !== page) return;
    const root = getScreenRoot();
    if (!root) return;

    const { stale = false, staleForMs = 0 } = freshness;
    if (page === "settings") {
      refreshSettingsStatusView();
      refreshStatusRefreshFeedback(root, freshness);
      return;
    }
    if (["companies", "master-home"].includes(page)) {
      store.state.companyStatusStale = stale;
      const staleMessage = stale ? statusRefreshMessage(staleForMs) : null;
      if (page === "master-home" && companyStatusViewSignature() !== getCompanyViewSignature()) {
        const scrollTop = window.scrollY;
        renderScreen();
        refreshCompanyStatusCards(store.state.companies, root, { stale, staleMessage });
        window.scrollTo(0, scrollTop);
        refreshRelativeTimes();
        return;
      }
      if (page === "companies") {
        const template = document.createElement("template");
        template.innerHTML = screens[page](store);
        const next = template.content.querySelector('[data-status-region="company-status-overview"]');
        const current = root.querySelector('[data-status-region="company-status-overview"]');
        const scrollTop = window.scrollY;
        const focusedControl = document.activeElement?.matches?.("input, select, textarea, button, [contenteditable='true']")
          && current?.contains(document.activeElement);
        if (next && current && !focusedControl && current.innerHTML !== next.innerHTML) {
          current.innerHTML = next.innerHTML;
          window.scrollTo(0, scrollTop);
        }
      }
      refreshCompanyStatusCards(store.state.companies, root, { stale, staleMessage });
      refreshRelativeTimes();
      bindActions();
      return;
    }

    const template = document.createElement("template");
    template.innerHTML = screens[page](store);
    const nextRegions = new Map(
      [...template.content.querySelectorAll("[data-status-region]")]
        .map((node) => [node.dataset.statusRegion, node]),
    );
    const activeElement = document.activeElement;
    const focusedControl = activeElement?.matches?.(
      "input, select, textarea, button, [contenteditable='true']",
    ) ? activeElement : null;
    const scrollTop = window.scrollY;
    let didPatch = false;
    root.querySelectorAll("[data-status-region]").forEach((region) => {
      const next = nextRegions.get(region.dataset.statusRegion);
      if (!next || focusedControl && region.contains(focusedControl)) return;
      const nextClassName = next.className;
      const nextHidden = next.hidden;
      const attributesChanged = ["aria-label", "aria-live", "aria-busy"].some((attribute) =>
        region.getAttribute(attribute) !== next.getAttribute(attribute),
      );
      if (
        region.innerHTML === next.innerHTML &&
        region.className === nextClassName &&
        region.hidden === nextHidden &&
        !attributesChanged
      ) return;
      const selectedEquipment = region.dataset.statusRegion === "division-equipment"
        ? region.querySelector('select[name="equipment_id"]')?.value
        : null;
      region.innerHTML = next.innerHTML;
      region.className = nextClassName;
      region.hidden = nextHidden;
      if (selectedEquipment) {
        const equipmentSelect = region.querySelector('select[name="equipment_id"]');
        if ([...(equipmentSelect?.options || [])].some((option) => option.value === selectedEquipment && !option.disabled)) {
          equipmentSelect.value = selectedEquipment;
        }
      }
      ["aria-label", "aria-live", "aria-busy"].forEach((attribute) => {
        if (next.hasAttribute(attribute)) region.setAttribute(attribute, next.getAttribute(attribute));
        else region.removeAttribute(attribute);
      });
      didPatch = true;
    });

    if (didPatch) window.scrollTo(0, scrollTop);
    if (["dalas", "dala"].includes(page)) renderDalaStatuses(store);
    if (page === "company") refreshIndustrialInstallationStatuses();
    refreshStatusRefreshFeedback(root, freshness);
    bindActions();
    refreshRelativeTimes();
  }

  return {
    companyStatusViewSignature,
    workStructureSignature,
    refreshWorkLiveView,
    refreshSettingsStatusView,
    refreshLiveStatusView,
    refreshStatusRefreshFeedback,
    installRelativeTimeRefresh,
  };
}
