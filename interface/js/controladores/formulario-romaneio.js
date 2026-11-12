import { setFormFeedback } from "../componentes/form-feedback.js?v=202611120001";
import { agora } from "../funcoes/relogio.js?v=202611120001";
import { validarCadastroRomaneio } from "../funcoes/romaneio.js?v=202611120001";

function dataLocalAtual(now) {
  const date = now();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${day}`;
}

function itensDoFormulario(form) {
  return [...form.querySelectorAll("#manifest-items tbody tr")].map((row) => ({
    product_id: row.querySelector('[name="item_product"]')?.value,
    quantity: row.querySelector('[name="item_quantity"]')?.value,
  }));
}

function bloquearBotoes(form, disabled) {
  form.querySelectorAll("button").forEach((button) => {
    button.disabled = disabled;
  });
}

function vincularBuscaProdutos(form) {
  if (form.dataset.productSearchBound === "1") return;
  form.dataset.productSearchBound = "1";

  const normalized = (value) => String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("pt-BR");
  const view = form.ownerDocument.defaultView;
  let activeSearchInput = null;

  function fechar(input, restoreFocus = false) {
    const wrapper = input.closest("[data-product-combobox]");
    const menu = wrapper?.querySelector("[data-product-menu]");
    const results = wrapper?.querySelector(".product-search-results");
    if (!wrapper || !menu || !results) return;
    menu.classList.remove("is-open");
    if (menu.dataset.popoverOpen === "1" && typeof menu.hidePopover === "function") {
      menu.hidePopover();
      menu.dataset.popoverOpen = "0";
    } else {
      menu.hidden = true;
    }
    results.replaceChildren();
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    if (activeSearchInput === input) {
      activeSearchInput = null;
      view.removeEventListener("scroll", closeOnScroll, true);
      view.removeEventListener("resize", closeOnResize);
      view.removeEventListener("pointerdown", closeOnOutsidePointer, true);
    }
    if (restoreFocus) input.focus({ preventScroll: true });
  }

  function closeOnScroll(event) {
    if (!activeSearchInput) return;
    const menu = activeSearchInput.closest("[data-product-combobox]")?.querySelector("[data-product-menu]");
    if (menu?.contains(event.target)) return;
    fechar(activeSearchInput);
  }

  function closeOnResize() {
    if (activeSearchInput) fechar(activeSearchInput);
  }

  function closeOnOutsidePointer(event) {
    if (!activeSearchInput) return;
    const wrapper = activeSearchInput.closest("[data-product-combobox]");
    if (!wrapper?.contains(event.target)) fechar(activeSearchInput);
  }

  const acompanhar = (input) => {
    if (activeSearchInput === input) return;
    if (activeSearchInput) fechar(activeSearchInput);
    activeSearchInput = input;
    view.addEventListener("scroll", closeOnScroll, true);
    view.addEventListener("resize", closeOnResize);
    view.addEventListener("pointerdown", closeOnOutsidePointer, true);
  };

  const fecharOutros = (currentWrapper = null) => {
    form.querySelectorAll("[data-product-combobox]").forEach((wrapper) => {
      if (wrapper !== currentWrapper) {
        const input = wrapper.querySelector("[data-product-search]");
        if (input) fechar(input);
      }
    });
  };

  const escolher = (input, option) => {
    const wrapper = input.closest("[data-product-combobox]");
    const productField = wrapper?.querySelector('[name="item_product"]');
    if (!wrapper || !productField) return;
    productField.value = option.dataset.productId || "";
    input.value = option.dataset.value || "";
    input.dataset.selectedValue = input.value;
    input.classList.remove("is-placeholder");
    fechar(input);
    input.focus({ preventScroll: true });
  };

  const posicionarMenu = (input) => {
    const wrapper = input.closest("[data-product-combobox]");
    const menu = wrapper?.querySelector("[data-product-menu]");
    if (!menu) return;
    const inputRect = input.getBoundingClientRect();
    const maxWidth = Math.max(160, view.innerWidth - 24);
    const menuWidth = Math.min(inputRect.width, maxWidth);
    const maxHeight = Math.max(80, Math.min(360, view.innerHeight - inputRect.bottom - 16));
    menu.style.width = `${menuWidth}px`;
    menu.style.maxHeight = `${maxHeight}px`;
    menu.style.left = `${Math.max(12, Math.min(inputRect.left, view.innerWidth - menuWidth - 12))}px`;
    menu.style.top = `${inputRect.bottom + 5}px`;
  };

  const mostrarResultados = (input) => {
    const wrapper = input.closest("[data-product-combobox]");
    const menu = wrapper?.querySelector("[data-product-menu]");
    const results = wrapper?.querySelector(".product-search-results");
    const catalog = form.querySelectorAll("#manifest-product-options [data-product-id]");
    const query = normalized(input.value.trim());
    if (!wrapper || !menu || !results) return;

    const matches = [...catalog]
      .filter((option) => !query || normalized(option.dataset.value).includes(query))
      .sort((left, right) => {
        const rank = (option) => {
          const name = normalized(option.dataset.name);
          const code = normalized(option.dataset.code);
          if (query && code === query) return 0;
          if (query && (code.startsWith(query) || name.startsWith(query))) return 1;
          return 2;
        };
        return rank(left) - rank(right)
          || (left.dataset.name || left.dataset.value).localeCompare(
            right.dataset.name || right.dataset.value,
            "pt-BR",
            { sensitivity: "base" },
          );
      });
    results.replaceChildren();
    input.removeAttribute("aria-activedescendant");

    if (!matches.length) {
      const empty = form.ownerDocument.createElement("div");
      empty.className = "product-search-empty";
      empty.setAttribute("role", "option");
      empty.setAttribute("aria-disabled", "true");
      empty.textContent = "Nenhum produto encontrado.";
      results.append(empty);
    } else {
      matches.slice(0, 8).forEach((option, index) => {
        const result = form.ownerDocument.createElement("button");
        result.type = "button";
        result.className = "product-search-option";
        result.tabIndex = -1;
        result.id = `${results.id}-option-${index}`;
        result.setAttribute("role", "option");
        result.setAttribute("aria-selected", "false");
        result.dataset.productOption = "1";
        result.dataset.productId = option.dataset.productId;
        result.dataset.value = option.dataset.value;
        result.title = option.dataset.value;

        const name = form.ownerDocument.createElement("span");
        name.className = "product-search-option-name";
        name.textContent = option.dataset.name || option.dataset.value;
        result.append(name);
        if (option.dataset.code) {
          const code = form.ownerDocument.createElement("small");
          code.className = "product-search-option-code";
          code.textContent = option.dataset.code;
          result.append(code);
        }
        results.append(result);
      });

      if (matches.length > 8) {
        const hint = form.ownerDocument.createElement("div");
        hint.className = "product-search-hint";
        hint.setAttribute("role", "option");
        hint.setAttribute("aria-disabled", "true");
        hint.textContent = query
          ? `Mostrando 8 de ${matches.length}. Digite mais para refinar.`
          : `Mostrando 8 de ${matches.length}. Digite para buscar.`;
        results.append(hint);
      }
    }

    posicionarMenu(input);
    input.setAttribute("aria-expanded", "true");
  };

  const abrir = (input) => {
    const wrapper = input.closest("[data-product-combobox]");
    const menu = wrapper?.querySelector("[data-product-menu]");
    if (!wrapper || !input || !menu) return;
    if (menu.classList.contains("is-open")) {
      mostrarResultados(input);
      return;
    }
    fecharOutros(wrapper);
    menu.classList.add("is-open");
    if (typeof menu.showPopover === "function") {
      menu.showPopover();
      menu.dataset.popoverOpen = "1";
    } else {
      menu.hidden = false;
    }
    acompanhar(input);
    mostrarResultados(input);
    input.focus({ preventScroll: true });
  };

  form.addEventListener("input", (event) => {
    const input = event.target.closest?.("[data-product-search]");
    if (!input || !form.contains(input)) return;
    if (input.value !== input.dataset.selectedValue) {
      const productField = input.closest("[data-product-combobox]")?.querySelector('[name="item_product"]');
      if (productField) productField.value = "";
      input.dataset.selectedValue = "";
    }
    input.classList.remove("is-placeholder");
    abrir(input);
  });

  form.addEventListener("focusin", (event) => {
    const wrapper = event.target.closest?.("[data-product-combobox]");
    fecharOutros(wrapper && form.contains(wrapper) ? wrapper : null);
    const input = event.target.closest?.("[data-product-search]");
    if (input && form.contains(input)) {
      abrir(input);
    }
  });

  form.addEventListener("focusout", (event) => {
    const input = event.target.closest?.("[data-product-search]");
    if (!input) return;
    form.ownerDocument.defaultView.setTimeout(() => {
      const wrapper = input.closest("[data-product-combobox]");
      if (!wrapper?.contains(form.ownerDocument.activeElement)) fechar(input);
    }, 100);
  });

  form.addEventListener("pointerdown", (event) => {
    if (event.target.closest?.("[data-product-option]") && event.pointerType === "mouse") {
      event.preventDefault();
    }
  });

  form.addEventListener("click", (event) => {
    const option = event.target.closest?.("[data-product-option]");
    if (option && form.contains(option)) {
      const input = option.closest("[data-product-combobox]")?.querySelector("[data-product-search]");
      if (input) escolher(input, option);
      return;
    }
    if (!event.target.closest?.("[data-product-combobox]")) fecharOutros();
  });

  form.addEventListener("keydown", (event) => {
    const input = event.target.closest?.("[data-product-search]");
    if (!input) return;
    if (!input.closest("[data-product-menu]")?.classList.contains("is-open")) abrir(input);
    const results = input.closest("[data-product-combobox]")?.querySelector(".product-search-results");
    const options = [...(results?.querySelectorAll("[data-product-option]") || [])];
    const activeIndex = options.findIndex((option) => option.getAttribute("aria-selected") === "true");

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      if (!options.length) return;
      event.preventDefault();
      const direction = event.key === "ArrowDown" ? 1 : -1;
      const nextIndex = activeIndex < 0
        ? (direction > 0 ? 0 : options.length - 1)
        : (activeIndex + direction + options.length) % options.length;
      options.forEach((option, index) => option.setAttribute("aria-selected", String(index === nextIndex)));
      input.setAttribute("aria-activedescendant", options[nextIndex].id);
      options[nextIndex].scrollIntoView({ block: "nearest" });
    } else if (event.key === "Enter") {
      event.preventDefault();
      const selected = options[activeIndex] || options[0];
      if (selected) escolher(input, selected);
    } else if (event.key === "Escape") {
      event.preventDefault();
      fechar(input, true);
    } else if (event.key === "Tab") {
      fechar(input);
    }
  });
}

function preencherSelectInstalacoes(select, installations) {
  if (!select) return;
  const valorAtual = select.value;
  // Manter opção vazia
  while (select.options.length > 1) select.remove(1);
  for (const inst of installations || []) {
    const option = document.createElement("option");
    option.value = inst.id;
    option.textContent = inst.name || `Instalação ${inst.id}`;
    select.appendChild(option);
  }
  if (valorAtual) select.value = valorAtual;
  // Se há somente uma instalação, selecionar automaticamente
  if ((installations || []).length === 1 && select.options.length === 2) {
    select.value = installations[0].id;
  }
}

export function bindManifestCreationForm({
  form,
  store,
  navigate,
  onUnauthorized,
  now = agora,
  readForm = (target) => Object.fromEntries(new FormData(target)),
  readItems = itensDoFormulario,
} = {}) {
  if (!form) return false;
  vincularBuscaProdutos(form);

  // Carregar instalações disponíveis para o select
  const installationSelect = form.querySelector("[name='installation_id']");
  if (installationSelect) {
    store.loadInstallations().then(() => {
      preencherSelectInstalacoes(installationSelect, store.state.installations);
    }).catch(() => {
      // Falha silenciosa — o usuário pode continuar sem instalação selecionada
    });
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === "1") return;

    const raw = readForm(form);
    const scheduledDate = raw.scheduled_date;
    const items = readItems(form);
    const validationError = validarCadastroRomaneio({
      scheduledDate,
      todayString: dataLocalAtual(now),
      items,
    });
    if (validationError) {
      setFormFeedback(form, validationError, "error");
      return;
    }

    form.dataset.submitting = "1";
    bloquearBotoes(form, true);
    setFormFeedback(form, "Salvando romaneio…");

    const installationId = raw.installation_id ? Number(raw.installation_id) : undefined;

    try {
      await store.createManifest({
        number: raw.number,
        scheduled_date: scheduledDate,
        plate: raw.plate,
        expedidor: raw.expedidor,
        driver_name: raw.driver_name,
        ...(installationId ? { installation_id: installationId } : {}),
        items: items.map((item) => ({
          product_id: item.product_id,
          quantity: Number(item.quantity),
        })),
      });
    } catch (error) {
      if (error.status === 401) {
        onUnauthorized(error);
        return;
      }
      setFormFeedback(
        form,
        error.message || "Não foi possível salvar o romaneio. Confira os dados e tente novamente.",
        "error",
      );
      return;
    } finally {
      form.dataset.submitting = "0";
      bloquearBotoes(form, false);
    }

    if (form.isConnected) {
      setFormFeedback(form, "Romaneio cadastrado com sucesso.");
      await navigate("manifests");
    }
  });

  return true;
}

/** Liga a edição de romaneio preservando os itens separados do mesmo produto. */
export function bindManifestEditForm({
  form,
  store,
  navigate,
  notify,
  now = agora,
  readForm = (target) => Object.fromEntries(new FormData(target)),
  readItems = itensDoFormulario,
} = {}) {
  if (!form) return false;
  vincularBuscaProdutos(form);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const raw = readForm(form);
    const todayString = dataLocalAtual(now);
    if (!raw.scheduled_date || raw.scheduled_date < todayString) {
      notify("Informe uma data igual ou posterior ao dia atual do PC industrial.");
      return;
    }

    const items = readItems(form)
      .filter((item) => item.product_id && Number(item.quantity) >= 1);
    if (!items.length) {
      notify("Adicione ao menos um item com produto e quantidade.");
      return;
    }
    if (form.dataset.submitting === "1") return;

    form.dataset.submitting = "1";
    bloquearBotoes(form, true);
    try {
      await store.updateManifest({
        romaneio_id: form.dataset.id,
        number: raw.number,
        scheduled_date: raw.scheduled_date,
        plate: raw.plate,
        expedidor: raw.expedidor,
        driver_name: raw.driver_name,
        items,
      });
      notify("Romaneio atualizado.");
      await navigate("manifest", `?id=${form.dataset.id}`);
    } catch (error) {
      notify(error.message);
    } finally {
      form.dataset.submitting = "0";
      bloquearBotoes(form, false);
    }
  });

  return true;
}
