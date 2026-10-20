import { agora } from "./relogio.js?v=202609170015";

const traceTimeZone = "America/Sao_Paulo";
const dateOnly = new Intl.DateTimeFormat("pt-BR", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  timeZone: traceTimeZone,
});
const dateTime = new Intl.DateTimeFormat("pt-BR", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
  timeZone: traceTimeZone,
});
const numberFormat = new Intl.NumberFormat("pt-BR");

function parseDate(value) {
  if (!value) return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const normalized = String(value).trim().replace(" ", "T");
  // Datas de calendário não representam um instante UTC. Construí-las com
  // `new Date("AAAA-MM-DD")` desloca a exibição para o dia anterior em fusos
  // como o de Brasília. O meio-dia UTC mantém a data do calendário estável
  // quando ela é formatada no fuso oficial do Trace.
  const dateOnlyMatch = normalized.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (dateOnlyMatch) {
    const [, year, month, day] = dateOnlyMatch;
    const calendarDate = new Date(`${year}-${month}-${day}T12:00:00Z`);
    return Number.isNaN(calendarDate.getTime()) ? null : calendarDate;
  }
  // MySQL grava os timestamps operacionais em UTC sem anexar o fuso. Sem o
  // sufixo `Z`, o navegador interpreta o valor como horário local e exibe
  // três horas adiantado em Brasília. Valores que já trazem offset continuam
  // sendo respeitados.
  const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
  const parsed = new Date(hasTimezone ? normalized : `${normalized}Z`);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function data(value, fallback = "—") {
  const parsed = parseDate(value);
  return parsed ? dateOnly.format(parsed) : fallback;
}

export function dataHora(value, fallback = "—") {
  const parsed = parseDate(value);
  return parsed ? dateTime.format(parsed) : fallback;
}

export function numero(value, fallback = "0") {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? numberFormat.format(parsed) : fallback;
}

export function relativo(value, fallback = "Sem sinal") {
  const parsed = parseDate(value);
  if (!parsed) return fallback;
  const seconds = Math.max(0, Math.floor((agora().getTime() - parsed.getTime()) / 1000));
  if (seconds < 60) return `há ${seconds} s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `há ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `há ${hours} h`;
  return `há ${Math.floor(hours / 24)} d`;
}
