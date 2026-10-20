const ESTADOS = {
  AGUARDANDO: "Aguardando",
  PREPARANDO: "Preparando",
  CARREGANDO: "Carregando",
  PAUSADO: "Pausado",
  FINALIZANDO: "Finalizando",
  FINALIZADO: "Finalizado",
  EMERGENCIA: "Emergência",
  ERRO: "Erro",
  REJEITADO: "Rejeitado",
};

const COMANDOS = {
  INICIAR_CARREGAMENTO: "Iniciar carregamento",
  PAUSAR_CARREGAMENTO: "Pausar carregamento",
  REVERSAO_ATIVAR: "Ativar reversão",
  REVERSAO_DESATIVAR: "Desativar reversão",
  EMERGENCIA: "Emergência",
};

const EVENTOS = {
  QUANTIDADE_PLANEJADA_ATINGIDA: "Operação atingir 100%",
};

const STATUS_COMANDO = {
  PENDENTE: "Pendente",
  PROCESSANDO: "Em processamento no gateway",
  APLICADO: "Aplicado pelo gateway",
  REJEITADO: "Rejeitado pelo gateway",
  CONCLUIDO: "Concluído",
  SUCESSO: "Concluído",
  ERRO: "Erro",
  EXPIRADO: "Tempo de resposta esgotado",
  CANCELADO: "Cancelado",
};

const STATUS_SINCRONIZACAO = {
  PENDENTE: "Pendente",
  PROCESSANDO: "Processando",
  ENVIADO: "Enviado",
  ERRO: "Com erro",
};

const OCORRENCIAS = {
  SACA_RASGADA: "Saca rasgada",
  SACA_AVARIADA: "Saca avariada",
  PARADA_MAQUINA: "Parada de máquina",
  LIMPEZA_LINHA: "Limpeza de linha",
  QUEDA_ENERGIA: "Queda de energia",
  AJUSTE_EQUIPAMENTO: "Ajuste de equipamento",
  FALHA_ELETRICA: "Falha elétrica",
};

function labelFrom(map, value, fallback = "—") {
  const normalized = String(value || "").trim().toUpperCase();
  return map[normalized] || (normalized ? normalized.replaceAll("_", " ") : fallback);
}

export function rotuloEstado(value, fallback = "Sem operação") {
  return labelFrom(ESTADOS, value, fallback);
}

export function rotuloComando(value) {
  return labelFrom(COMANDOS, value);
}

export function rotuloEvento(value) {
  return labelFrom(EVENTOS, value);
}

export function rotuloStatusComando(value) {
  return labelFrom(STATUS_COMANDO, value);
}

export function rotuloStatusSincronizacao(value) {
  return labelFrom(STATUS_SINCRONIZACAO, value);
}

export function rotuloStatusRomaneio(value) {
  return labelFrom({
    IMPORTADO: "Importado",
    AGUARDANDO: "Aguardando",
    EM_ANDAMENTO: "Em andamento",
    FINALIZADO: "Finalizado",
    CANCELADO: "Cancelado",
  }, value);
}

export function rotuloOcorrencia(value) {
  return labelFrom(OCORRENCIAS, value);
}
