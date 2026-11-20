import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const stateEndpoint = await readFile(new URL("../servidor/api/operacoes/estado_carregamento.php", import.meta.url), "utf8");
const commandEndpoint = await readFile(new URL("../servidor/api/operacoes/comando_maquina.php", import.meta.url), "utf8");
const commandService = await readFile(new URL("../servidor/src/Aplicacao/ServicoComandoClp.php", import.meta.url), "utf8");
const stateService = await readFile(new URL("../servidor/src/Aplicacao/ServicoEstadoCarregamento.php", import.meta.url), "utf8");
const gatewayService = await readFile(new URL("../servidor/src/Aplicacao/ServicoGatewayClp.php", import.meta.url), "utf8");

test("início e parada pedem confirmação física ao gateway antes de alterar o estado", () => {
  assert.match(
    stateEndpoint,
    /\$target === "CARREGANDO"\)\s*\{\s*\$data = \$commands->solicitarOperacaoManual\(\$user, \(int\) \$loadingId, "INICIAR_CARREGAMENTO"\)/s,
  );
  assert.match(
    stateEndpoint,
    /\$target === "PAUSADO"\)\s*\{\s*\$data = \$commands->solicitarOperacaoManual\(\$user, \(int\) \$loadingId, "PAUSAR_CARREGAMENTO"\)/s,
  );
  assert.match(stateService, /in_array\(\$target, \["CARREGANDO", "PAUSADO"\], true\)/);
  assert.match(stateService, /\$target === "FINALIZANDO"/);
  assert.match(gatewayService, /\$status === "APLICADO" && in_array\(\$request\["command"\], \["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"\]/);
});


test("as duas rotas de movimento manual exigem perfil de operador", () => {
  assert.match(commandEndpoint, /\$operationCommand\s*=>\s*\$service->solicitarOperacaoManual\(/);
  assert.match(commandService, /function solicitarOperacaoManual\([\s\S]*?\$user\["role"\][\s\S]*?"USUARIO"[\s\S]*?403[\s\S]*?return \$this->solicitarOperacao\(/);
});
