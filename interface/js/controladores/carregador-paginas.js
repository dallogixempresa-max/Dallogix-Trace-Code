export function criarCarregadorPaginas({
  store,
  obterPerfil,
  obterModoInstalacao,
  idDaConsulta,
  registrarAviso,
}) {
  async function carregarDetalheDala(id) {
    await store.loadEquipment(id);
    const results = await Promise.allSettled([
      store.loadDalaCommandHistory(id),
      store.loadDalaStatuses(),
    ]);
    results.forEach((result) => {
      if (result.status === "rejected") {
        registrarAviso("dala.consulta-auxiliar", result.reason);
      }
    });
  }

  const carregadoresIniciais = {
    dashboard: () => [store.loadEquipments(), store.loadDalaStatuses()],
    manifests: () => [store.loadManifests()],
    manifest: () => [store.loadManifest(idDaConsulta())],
    "manifest-edit": () => [store.loadManifest(idDaConsulta()), store.loadProducts()],
    import: () => [store.loadProducts()],
    division: () => [
      store.loadManifest(idDaConsulta()),
      store.loadEquipments(),
      store.loadActiveLoading(),
    ],
    work: () => [
      store.loadActiveLoading().then(() => store.loadPendingReadings()),
      store.loadDeviceStatuses(),
      store.loadEquipments(),
    ],
    occurrences: () => [store.loadOccurrences(), store.loadActiveLoading()],
    summary: () => [store.loadSummaryMonitoring(), store.loadActiveLoading()],
    products: () => [store.loadProducts()],
    alerts: () => [store.loadAlertMonitoring(), store.loadSyncStatus()],
    emergency: () => [store.loadActiveLoading(), store.loadDeviceStatuses()],
    settings: () => [
      store.loadConfiguration(),
      store.loadDeviceStatuses(),
      store.loadEquipments(),
      store.loadSyncStatus(),
      store.loadDalaStatuses(),
    ],
    dalas: () => [store.loadEquipments(), store.loadDalaStatuses()],
    dala: () => [carregarDetalheDala(idDaConsulta())],
    "dala-edit": () => [store.loadEquipment(idDaConsulta())],
    "error-logs": () => [
      store.loadErrorLogs(),
      store.loadTechnicalDiagnostics(),
      store.loadDeadLetters(),
    ],
    users: () => [store.loadUsers()],
  };

  const carregadoresAoVivo = {
    dashboard: () => [carregarStatusEquipamentos()],
    manifests: () => [store.loadManifests()],
    manifest: () => [store.loadManifest(idDaConsulta())],
    occurrences: () => [store.loadOccurrences()],
    summary: () => [store.loadSummaryMonitoring(), store.loadActiveLoading(store.state.selectedLoadingId)],
    division: () => [
      store.loadManifest(idDaConsulta()),
      store.loadActiveLoading(),
      carregarStatusEquipamentos(),
    ],
    products: () => [store.loadProducts({ force: true })],
    alerts: () => [store.loadAlertMonitoring(), store.loadSyncStatus()],
    dalas: () => [carregarStatusEquipamentos()],
    dala: () => [store.loadDalaCommandHistory(idDaConsulta())],
    users: () => [store.loadUsers()],
    settings: () => [store.loadDeviceStatuses(), store.loadSyncStatus()],
    companies: () => [store.loadCompanies()],
    "master-home": () => [store.loadCompanies()],
    company: () => [store.loadCompanyDetail()],
    "error-logs": () => [store.loadErrorLogs(), store.loadTechnicalDiagnostics(), store.loadDeadLetters()],
  };

  async function carregarStatusEquipamentos() {
    // No PC local, o cadastro é estático; no Central, o heartbeat precisa ser revalidado.
    const force = obterModoInstalacao() === "central" || Boolean(store.state.equipmentsError);
    await store.loadEquipments({ force });
    if (store.state.equipmentsError) throw new Error(store.state.equipmentsError);
  }

  async function carregarTelasAdministrativas(page) {
    await store.loadCompanies();
    if (["master-home", "error-logs"].includes(page)) {
      await store.loadErrorLogs();
      if (page === "error-logs") await store.loadTechnicalDiagnostics();
    }
    if (page === "company") {
      const id = idDaConsulta();
      if (!id) throw new Error("Empresa não informada.");
      store.selectCompany(id);
      await store.loadCompanyDetail();
    }
    if (page === "users") {
      store.state.selectedCompanyId =
        new URLSearchParams(window.location.search).get("company_id") || null;
      if (store.state.selectedCompanyId) await store.loadUsers();
    }
    if (page === "settings") {
      await Promise.all([
        store.loadConfiguration(),
        store.loadDeviceStatuses(),
        store.loadEquipments(),
        store.loadSyncStatus(),
        store.loadDalaStatuses(),
      ]);
    }
  }

  async function carregarDadosIniciais(page) {
    if (obterPerfil() === "ADMIN_DALLOGIX") {
      await carregarTelasAdministrativas(page);
      return;
    }

    if (["manifest", "manifest-edit", "division", "dala", "dala-edit"].includes(page) && !idDaConsulta()) {
      throw new Error("Registro não informado.");
    }
    await Promise.all(carregadoresIniciais[page]?.() || []);
  }

  function obterConsultasAoVivo(page) {
    // Retorna cada requisição separadamente para o controlador de atualização
    // registrar todas as falhas com Promise.allSettled.
    return carregadoresAoVivo[page]?.() || [];
  }

  return {
    carregarDadosIniciais,
    obterConsultasAoVivo,
  };
}
