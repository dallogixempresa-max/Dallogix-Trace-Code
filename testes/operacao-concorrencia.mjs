import assert from 'node:assert/strict';
import test from 'node:test';
import { ServicoObservabilidade } from '../interface/js/servicos/ServicoObservabilidade.js';
import { criarExecutorComandosOperacao } from '../interface/js/controladores/executor-comandos-operacao.js';

function deferred() { let resolve; const promise = new Promise(r => { resolve = r; }); return {promise, resolve}; }
function monitoring(state, fetch) {
  return new ServicoObservabilidade({ state, api: { fetch }, jsonResponse: async x => x });
}
test('resposta atrasada de outra Dala não altera contagem nem resumo selecionado', async () => {
  const pending = deferred();
  const state = {loadingId:42, detectedBags:7, summaryMonitoring:{carregamento:{id:43}}};
  const service = monitoring(state, async () => pending.promise);
  const request = service.loadSummaryMonitoring();
  state.loadingId = 43;
  pending.resolve({data:{id:42,sacas_detectadas:100}});
  await request;
  assert.equal(state.detectedBags,7);
  assert.equal(state.summaryMonitoring.carregamento.id,43);
});
test('resposta antiga da mesma Dala não sobrescreve uma consulta mais nova', async () => {
  const pending = deferred(); let calls=0;
  const state={loadingId:42};
  const service=monitoring(state, () => ++calls<=2 ? pending.promise : Promise.resolve({data:{id:42,sacas_detectadas:9}}));
  const older=service.loadSummaryMonitoring();
  await service.loadSummaryMonitoring();
  pending.resolve({data:{id:42,sacas_detectadas:2}});
  await older;
  assert.equal(state.detectedBags,9);
});
test('resumo sem contagem não zera a contagem existente', async () => {
  const state={loadingId:42,detectedBags:7};
  await monitoring(state, async()=>({data:{sacas_detectadas:null}})).loadSummaryMonitoring();
  assert.equal(state.detectedBags,7);
});
function executor({busy=false, fail=false}={}) {
  const calls=[];
  const store={state:{loadingId:42,commandInFlight:busy},beginCommand:()=>busy?null:1,endCommand:t=>calls.push(['end',t]),requestMachineEmergency:async id=>calls.push(['emergency',id])};
  const execute=criarExecutorComandosOperacao({store,getPage:()=> 'work',render:()=>{},registrarErro:()=>{},notificarForaDaOperacao:()=>{},servicoOperacao:{iniciar:async id=>{calls.push(['start',id]);if(fail)throw new Error('Falha de comunicação');}},machineCommandQueue:{enqueue:async(id,cmd,run)=>run()}});
  return {execute,store,calls};
}
test('duplo clique não repete partida e emergência passa durante comando em voo', async()=>{
  const h=executor({busy:true});
  await h.execute({dataset:{}},'run');
  await h.execute({dataset:{}},'emergency');
  assert.deepEqual(h.calls,[['emergency',42]]);
});
test('falha da máquina libera a trava e mantém mensagem para o operador',async()=>{
  const h=executor({fail:true});
  await h.execute({dataset:{}},'run');
  assert.deepEqual(h.calls,[['start',42],['end',1]]);
  assert.equal(h.store.state.commandFeedback,'Falha de comunicação');
});
