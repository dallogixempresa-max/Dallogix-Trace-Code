import { FORM_ACTIONS } from "../constantes/acoes.js";

export const el = (selector) => document.querySelector(selector);
export const esc = (value) =>
  String(value ?? "").replace(
    /[&<>"']/g,
    (char) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      })[char],
  );
const safeButtonAttributes = (attributes) => {
  const entries = [];
  if (attributes && typeof attributes === "object" && !Array.isArray(attributes)) {
    entries.push(...Object.entries(attributes));
  } else {
    let remaining = String(attributes || "").trim();
    while (remaining) {
      const match = remaining.match(
        /^([a-z][a-z0-9-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'`=<>]+)))?(?:\s+|$)/i,
      );
      if (!match) break;
      entries.push([match[1], match[2] ?? match[3] ?? match[4] ?? true]);
      remaining = remaining.slice(match[0].length).trimStart();
    }
  }
  const safe = [];
  for (const [name, value] of entries) {
    const normalizedName = name.toLowerCase();
    if (value === null || value === undefined) continue;
    if (normalizedName === "disabled") {
      if (value !== false) safe.push("disabled");
    } else if (
      normalizedName === "title" ||
      (/^(?:data|aria)-[a-z0-9]+(?:-[a-z0-9]+)*$/.test(normalizedName) && normalizedName !== "data-action")
    ) {
      safe.push(`${normalizedName}="${esc(value)}"`);
    }
  }
  return safe.length ? ` ${safe.join(" ")}` : "";
};

export const button = (label, action, tone = "primary", attributes = "") =>
  `<button class="button ${esc(tone)}" data-action="${esc(action)}" type="${FORM_ACTIONS.has(action) ? "submit" : "button"}"${safeButtonAttributes(attributes)}>${esc(label)}</button>`;
