import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { workControlLocks } from '../interface/telas/operacao/operacao.js';
import { podeFinalizarCarregamento } from '../interface/js/funcoes/regras-carregamento.js';

const root = new URL('../', import.meta.url);
const source = await readFile(new URL('servidor/api/operacoes/carregamentos.php', root), 'utf8');
test('vínculo de Dala devolve estado aceito pelo início sem apagar horário original', () => {
  const patch = source.split('if ($_SERVER["REQUEST_METHOD"] === "PATCH") {')[1].split('if ($_SERVER["REQUEST_METHOD"] !== "POST")')[0];
  assert.match(patch, /\$current\["state"\] !== "AGUARDANDO"/);
  assert.match(patch, /SET equipment_id = :equipment_id, state = 'PREPARANDO'/);
  assert.doesNotMatch(patch, /started_at\s*=/);
  assert.equal([...patch.matchAll(/"state" => "PREPARANDO"/g)].length, 2, 'auditoria e resposta devem refletir o estado gravado');
  const locks = workControlLocks({loadingId:42, operationalState:'PREPARANDO',running:false,userRole:'USUARIO',reversalCommand:'REVERSAO_DESATIVAR',planned:10,detectedBags:0});
  assert.equal(locks.run, '');
});

test('rotas públicas consumidas pelo frontend apontam para os controladores da operação', async()=>{
  for(const route of ['carregamentos','comando_maquina','comandos_industriais','estado_carregamento','resumo_carregamento','encerrar_carregamento','leituras','retornos','desbloquear_maquina','confirmar_remocao']){
    const wrapper=await readFile(new URL(`servidor/api/${route}.php`,root),'utf8');
    assert.ok(wrapper.includes(`/operacoes/${route}.php`),route);
    await readFile(new URL(`servidor/api/operacoes/${route}.php`,root),'utf8');
  }
});

for(const [name,input,ready] of [
  ['fila agregada vazia',{pending_events:'0'},true],
  ['fila agregada pendente',{pending_events:'1'},false],
  ['sem heartbeat conhecido',{},false],
  ['contagem negativa',{pending_events:'-1'},false],
  ['contagem malformada',{pending_events:'erro'},false],
  ['fila da própria carga pendente',{pending_events_by_loading:'{"42":2}'},false],
  ['fila pendente de outra carga',{pending_events_by_loading:'{"43":2}'},true],
  ['mapa inválido',{pending_events_by_loading:'[]',pending_events:0},false],
]) {
  test(`JSON do validador PHP é interpretado pelo frontend: ${name}`,()=>{
    const script=`<?php require $argv[1]; $s=json_decode($argv[2],true); echo json_encode(['sensor_outbox_pendentes'=>App\\Aplicacao\\ValidadorFilaSensor::pendentes($s,42)]);`;
    const result=spawnSync('php',['--',new URL('servidor/src/Aplicacao/ValidadorFilaSensor.php',root).pathname,JSON.stringify(input)],{input:script,encoding:'utf8'});
    assert.equal(result.status,0,result.stderr);
    const response=JSON.parse(result.stdout);
    const state={loadingId:42,operationalState:'PAUSADO',planned:1,detectedBags:1,loadingItems:[{planned_quantity:1,loaded_quantity:1}],summaryMonitoring:{carregamento:{id:42,leituras_pendentes_sensor:0,imagens_pendentes:0,imagens_com_falha:0,...response}}};
    assert.equal(podeFinalizarCarregamento(state),ready);
  });
}
