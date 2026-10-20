function serializeCsvCell(value) {
  let text = String(value ?? "");
  // Planilhas interpretam células iniciadas por estes caracteres como fórmulas.
  if (/^[\t\r\n ]*[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replaceAll('"', '""')}"`;
}

/** Serializa dados em CSV separado por ponto e vírgula e protege contra fórmulas. */
export function serializeCsv(rows) {
  return rows.map((row) => row.map(serializeCsvCell).join(";")).join("\n");
}

/** Dispara um download no navegador e libera os recursos após o início da transferência. */
export function downloadBlob(blob, filename, browser = globalThis) {
  const url = browser.URL.createObjectURL(blob);
  const link = browser.document.createElement("a");
  link.href = url;
  link.download = filename;
  link.hidden = true;
  browser.document.body.append(link);
  link.click();
  browser.setTimeout(() => {
    link.remove();
    browser.URL.revokeObjectURL(url);
  }, 60000);
}

/** Cria um CSV UTF-8 com BOM para abrir corretamente em planilhas comuns. */
export function downloadCsv(filename, rows, browser = globalThis) {
  const blob = new browser.Blob([`\ufeff${serializeCsv(rows)}`], {
    type: "text/csv;charset=utf-8",
  });
  downloadBlob(blob, filename, browser);
}
