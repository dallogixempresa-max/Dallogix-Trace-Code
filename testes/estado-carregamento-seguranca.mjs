import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const stateEndpoint = await readFile(new URL("../servidor/api/operacoes/estado_carregamento.php", import.meta.url), "utf8");
const stateService = await readFile(new URL("../servidor/src/Aplicacao/ServicoEstadoCarregamento.php", import.meta.url), "utf8");
const gatewayService = await readFile(new URL("../servidor/src/Aplicacao/ServicoGatewayClp.php", import.meta.url), "utf8");

test("início e parada pedem confirmação física ao gateway antes de alterar o estado", () => {
  assert.match(
    stateEndpoint,
    /\$target === "CARREGANDO"\)\s*\{\s*\$data = \$commands->solicitarOperacao\(\$user, \(int\) \$loadingId, "INICIAR_CARREGAMENTO"\)/s,
  );
  assert.match(
    stateEndpoint,
    /\$target === "PAUSADO"\)\s*\{\s*\$data = \$commands->solicitarOperacao\(\$user, \(int\) \$loadingId, "PAUSAR_CARREGAMENTO"\)/s,
  );
  assert.match(stateService, /in_array\(\$target, \["CARREGANDO", "PAUSADO"\], true\)/);
  assert.match(stateService, /\$target === "FINALIZANDO"/);
  assert.match(gatewayService, /\$status === "APLICADO" && in_array\(\$request\["command"\], \["INICIAR_CARREGAMENTO", "PAUSAR_CARREGAMENTO"\]/);
});
