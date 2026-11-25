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
test('bloqueio do cancelamento informa que pode ser forçado',()=>{
  const result=cancel({state:'CARREGANDO'});
  assert.equal(result.status,409);
  assert.equal(result.body.can_force,true);
  assert.match(result.body.error,/pausa ou emergência/);
});
test('force só é aceito como booleano verdadeiro',()=>{
  const result=cancel({pending:null,force:'true'});
  assert.equal(result.status,409);
  assert.deepEqual(result.events,[]);
});
test('cancelamento sem bloqueio não é marcado como forçado mesmo com force',()=>{
  const result=cancel({force:true});
  assert.equal(result.status,200);
  assert.equal(result.loading_reason,'ROMANEIO CANCELADO: Teste de bancada');
  for(const detail of result.event_details) assert.equal(detail.forced,undefined);
});
for(const [name,scenario,check] of [['em movimento',{state:'CARREGANDO'},'DALA_EM_OPERACAO'],['fila pendente',{pending:1},'FILA_SENSOR_PENDENTE'],['fila desconhecida',{pending:null},'SENSOR_SEM_CONFIRMACAO'],['passagem sem registro',{unregistered:1},'PASSAGEM_SEM_REGISTRO']]) {
  test(`cancelamento forçado sobrepõe a trava: ${name}`,()=>{
    const result=cancel({...scenario,force:true});
    assert.equal(result.status,200);
    assert.equal(result.body.data.status,'CANCELADO');
    assert.deepEqual(result.events,['CARREGAMENTO_CANCELADO','ROMANEIO_CANCELADO']);
    assert.equal(result.loading_reason,'ROMANEIO CANCELADO (FORÇADO): Teste de bancada');
    const [loadingEvent,manifestEvent]=result.event_details;
    assert.equal(loadingEvent.forced,true);
    assert.deepEqual(loadingEvent.bypassed_checks.map((item)=>item.check),[check]);
    assert.equal(manifestEvent.forced,true);
    assert.deepEqual(manifestEvent.bypassed_checks,[{carregamento_id:73,checks:loadingEvent.bypassed_checks}]);
  });
}
test('cancelamento forçado registra todas as travas sobrepostas e mantém a prioridade da mensagem',()=>{
  const scenario={state:'CARREGANDO',pending:2,unregistered:3};
  const blocked=cancel(scenario);
  assert.equal(blocked.status,409);
  assert.match(blocked.body.error,/pausa ou emergência/);
  const forced=cancel({...scenario,force:true});
  assert.equal(forced.status,200);
  assert.deepEqual(forced.event_details[0].bypassed_checks,[
    {check:'DALA_EM_OPERACAO',state:'CARREGANDO'},
    {check:'PASSAGEM_SEM_REGISTRO',count:3},
    {check:'FILA_SENSOR_PENDENTE',count:2},
  ]);
});
