import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
function cancel(scenario={}) {
  const result=spawnSync('php',[new URL('./suporte/cancelamento-operacional.php',import.meta.url).pathname,JSON.stringify(scenario)],{encoding:'utf8'});
  assert.equal(result.status,0,result.stderr);
  return JSON.parse(result.stdout);
}
test('cancelamento não consulta fotos, preserva dados e registra os eventos',()=>{
  const result=cancel();
  assert.equal(result.status,200);
  assert.equal(result.body.data.status,'CANCELADO');
  assert.deepEqual(result.events,['CARREGAMENTO_CANCELADO','ROMANEIO_CANCELADO']);
});
for(const [name,scenario] of [['em movimento',{state:'CARREGANDO'}],['fila pendente',{pending:1}],['fila desconhecida',{pending:null}],['passagem sem registro',{unregistered:1}]]) {
  test(`cancelamento mantém proteção: ${name}`,()=>{
    const result=cancel(scenario);
    assert.equal(result.status,409);
    assert.deepEqual(result.events,[]);
  });
}
