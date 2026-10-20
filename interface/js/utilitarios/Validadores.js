function textoObrigatorio(valor, rotulo) {
  if (!String(valor ?? "").trim()) return `${rotulo} é obrigatório.`;
  return "";
}

export function validarIdentificadorDala(valor) {
  const identificador = String(valor ?? "").trim();
  if (!identificador) return "O identificador da Dala é obrigatório.";
  if (!/^[A-Za-z0-9_-]{1,30}$/.test(identificador)) {
    return "O identificador deve usar letras, números, hífen ou underscore (até 30 caracteres).";
  }
  return "";
}

export function validarEnderecoPlc(valor) {
  const endereco = String(valor ?? "").trim();
  if (!endereco) return "O endereço do CLP é obrigatório.";
  if (endereco.length > 253 || /\s/.test(endereco) || !/^[a-z0-9._:-]+$/i.test(endereco)) {
    return "Informe um endereço IP ou nome de rede válido, sem espaços.";
  }
  return "";
}

export function validarPortaTcp(valor) {
  const porta = Number(valor);
  if (!Number.isInteger(porta) || porta < 1 || porta > 65535) {
    return "A porta do CLP deve ser um número entre 1 e 65535.";
  }
  return "";
}

export function validarCodigoBarras(valor) {
  const codigo = String(valor ?? "").trim();
  if (!codigo) return "O código de barras é obrigatório.";
  if (!/^\d{8,14}$/.test(codigo)) {
    return "O código de barras deve conter de 8 a 14 números.";
  }
  return "";
}

export function validarDala(dados = {}) {
  return textoObrigatorio(dados.name, "O nome da Dala")
    || validarIdentificadorDala(dados.equipment_code)
    || validarEnderecoPlc(dados.plc_ip)
    || validarPortaTcp(dados.plc_port);
}

export function validarProduto(dados = {}) {
  return textoObrigatorio(dados.name, "O nome do produto")
    || validarCodigoBarras(dados.barcode);
}
