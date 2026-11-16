import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import {work} from '../interface/telas/operacao/operacao.js';
function finish(scenario={}) {
 const result=spawnSync('php',[new URL('./suporte/encerramento-operacional.php',import.meta.url).pathname,JSON.stringify(scenario)],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr);
 return JSON.parse(result.stdout);
}
test('endpoint real finaliza carregamento e romaneio pela meta sem leituras nem fotos prontas',()=>{
 const result=finish();
 assert.equal(result.status,200);
 assert.equal(result.body.data.state,'FINALIZADO');
 assert.equal(result.body.data.romaneio_finalizado,true);
 assert.ok(result.events.includes('CARREGAMENTO_FINALIZADO'));
 assert.ok(result.events.includes('ROMANEIO_FINALIZADO'));
 assert.ok(!result.events.includes('DIVERGENCIA_FINAL_REGISTRADA'));
});
for(const [name,scenario] of [['abaixo da meta',{detected:1}],['acima da meta',{detected:3}],['parada não confirmada',{stopApplied:0}],['comando pendente',{pendingCommands:1}],['emergência',{state:'EMERGENCIA'}]]) {
 test(`endpoint continua bloqueando ${name}`,()=>{
   const result=finish(scenario);assert.equal(result.status,409);
   assert.ok(!result.events.includes('CARREGAMENTO_FINALIZADO'));
 });
}
test('botão fica liberado na meta e parada aplicada mesmo com todas as pendências de relatório',()=>{
 const state={selectedLoadingId:42,loadingId:42,operationalState:'PAUSADO',stopReason:'COTA_ROMANEIO_ATINGIDA',userRole:'USUARIO',planned:2,detectedBags:2,loaded:0,running:false,reversalCommand:'REVERSAO_DESATIVAR',loadingItems:[{planned_quantity:2,loaded_quantity:0}],plcCommand:{command:'PAUSAR_CARREGAMENTO',status:'APLICADO'},summaryMonitoring:{carregamento:{id:42,leituras_pendentes_sensor:2,sensor_outbox_pendentes:null,imagens_pendentes:2,imagens_com_falha:1}}};
 const markup=work({state});
 const button=markup.match(/<button[^>]*data-action="finish"[^>]*>/)?.[0];
 assert.ok(button);assert.doesNotMatch(button,/\sdisabled/);
 assert.doesNotMatch(markup, /id="work-stop-dialog"|name="confirmar_remocao"/);
});
test('consultas do leitor e do timeout continuam tratando pendências após finalizar',()=>{
 const result=spawnSync('php',[new URL('./pendencias-apos-finalizacao.php',import.meta.url).pathname],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr);
});
