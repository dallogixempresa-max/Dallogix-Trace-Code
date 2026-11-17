import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
const nodes=JSON.parse(await readFile(new URL('../integracoes/node-red/trace-clp-bridge.flow.json',import.meta.url),'utf8'));
const gate=new Function('msg','env','flow',nodes.find(n=>n.id==='command-safe-gate').func);
const config={TRACE_API_URL:'https://trace.example',TRACE_PHYSICAL_CLP_ENABLED:'1',TRACE_IO_MAP_STATUS:'APPROVED',TRACE_MODBUS_MAP_EQUIPMENT_ID:'1',TRACE_MODBUS_COIL_CONVEYOR_RUN:'10',TRACE_MODBUS_COIL_REVERSAL:'11'};
const env={get:key=>config[key]};
function message(command){return {statusCode:200,traceToken:'test',traceEquipment:{id:1,plc_connect_ip:'192.0.2.1',plc_port:502},payload:{data:{id:1,command,loading_state:'PREPARANDO'}}};}
test('gateway rejeita comandos retirados sem gerar escrita física',()=>{
 for(const command of ['EMERGENCIA','DESBLOQUEAR_MAQUINA']){
  const [write,ack]=gate(message(command),env,{});
  assert.equal(write,null);assert.equal(ack.payload.status,'REJEITADO');
 }
});
test('partida e pausa usam somente suas bobinas sem exigir mapa de emergência',()=>{
 for(const command of ['INICIAR_CARREGAMENTO','PAUSAR_CARREGAMENTO']){
  const [write,ack]=gate(message(command),env,{});
  assert.equal(ack,null);assert.equal(write.commandMap.coil,10);
 }
 assert.ok(!nodes.some(n=>n.id.startsWith('command-reset-')));
});
test('cliente antigo não consegue criar solicitação de emergência no backend',()=>{
 const file=new URL('../servidor/src/Aplicacao/ServicoComandoClp.php',import.meta.url).pathname;
 const code=`require ${JSON.stringify(file)}; $s=(new ReflectionClass(App\\Aplicacao\\ServicoComandoClp::class))->newInstanceWithoutConstructor(); try {$s->solicitarEmergencia([],1);exit(2);} catch(App\\Aplicacao\\ExcecaoComandoClp $e){if($e->httpStatus!==410)exit(3);echo 'retired';}`;
 const r=spawnSync('php',['-r',code],{encoding:'utf8'});
 assert.equal(r.status,0,r.stderr);assert.equal(r.stdout,'retired');
});
