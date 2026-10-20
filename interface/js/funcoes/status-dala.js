import { esc } from "./html.js";

export function renderStatusDala(status, errorMessage = "") {
  const raw = String(status?.status || "DESCONHECIDO").trim().toUpperCase();
  const online = raw === "ONLINE";
  const offline = ["OFFLINE", "ERRO"].includes(raw);
  const tone = online ? "online" : offline ? "offline" : "unknown";
  const label = online ? "ON" : offline ? "OFF" : "—";
  const title = errorMessage
    ? `Falha ao consultar comunicação do CLP: ${errorMessage}`
    : online
      ? "CLP online"
      : raw === "ERRO"
        ? "Falha de comunicação com o CLP"
        : raw === "OFFLINE"
          ? "CLP sem comunicação"
          : "Status do CLP indisponível";
  return `<span class="status-dot ${tone}" aria-hidden="true"></span><span class="dala-status-label ${tone}" role="status" aria-label="${esc(title)}" title="${esc(title)}">${label}</span>`;
}
