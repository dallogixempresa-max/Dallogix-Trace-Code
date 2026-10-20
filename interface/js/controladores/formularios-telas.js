function registrarOcorrencia({ root, store, render, notify, onUnauthorized, readForm }) {
  const form = root.querySelector("#occurrence-form");
  if (!form) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    try {
      await store.createOccurrence(readForm(form));
      render();
    } catch (error) {
      if (error.status === 401) {
        onUnauthorized(error, "ocorrencia");
        return;
      }
      notify(error.message);
    }
  });
}

function salvarParametrosPdf({ root, store, render, notify, readForm }) {
  const form = root.querySelector("#pdf-settings-form");
  if (!form) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const raw = readForm(form);
    // Campos ausentes do formulário podem conter configurações legadas úteis.
    const pdf_field_mapping = Object.fromEntries(
      Object.entries(raw)
        .filter(([key, value]) => key.startsWith("pdf_") && key !== "pdf_search_field" && value)
        .map(([key, value]) => [key.slice(4), value]),
    );
    const saved = store.state.configuration?.settings || {};

    try {
      await store.saveConfiguration({
        gateway_public_ip: saved.gateway_public_ip || "",
        pdf_field_mapping,
        pdf_search_field: raw.pdf_search_field,
      });
      notify("Parâmetros salvos.");
      render();
    } catch (error) {
      notify(error.message);
    }
  });
}

function filtrarRomaneios({ root, store, render, notify, readForm }) {
  const form = root.querySelector("#manifest-filters");
  if (!form) return;

  const apply = async () => {
    try {
      await store.applyManifestFilters(readForm(form));
      render();
    } catch (error) {
      notify(error.message);
    }
  };
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await apply();
  });
  form.addEventListener("change", apply);
}

function filtrarProdutos({ root, store }) {
  const input = root.querySelector("#product-search");
  if (!input) return;

  input.addEventListener("input", () => {
    const term = input.value.toLowerCase();
    store.state.productSearch = input.value;
    root.querySelectorAll(".table-wrap tbody tr").forEach((row) => {
      row.style.display = !term || row.textContent.toLowerCase().includes(term) ? "" : "none";
    });
  });
}

/** Conecta filtros e formulários de telas fora do fluxo operacional. */
export function bindPageForms({
  root = document,
  store,
  render,
  notify,
  onUnauthorized = () => {},
  readForm = (form) => Object.fromEntries(new FormData(form)),
} = {}) {
  registrarOcorrencia({ root, store, render, notify, onUnauthorized, readForm });
  salvarParametrosPdf({ root, store, render, notify, readForm });
  filtrarRomaneios({ root, store, render, notify, readForm });
  filtrarProdutos({ root, store });
}
