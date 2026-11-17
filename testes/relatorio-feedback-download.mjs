import test from 'node:test';
import assert from 'node:assert/strict';
import { criarTratadorAcoesRelatorios } from '../interface/js/controladores/acoes-relatorios.js';
function scenario(response) {
 const seen={cleared:0,clicked:0,messages:[]};
 const browser={URL:{createObjectURL:()=> 'blob:test'},setTimeout:()=>{},document:{
  getElementById:()=>({remove:()=>seen.cleared++}),
  createElement:()=>({click(){seen.clicked++;seen.filename=this.download;}}),body:{append(){}}
 }};
 const action=criarTratadorAcoesRelatorios({api:{fetch:async url=>{seen.url=url;return response;}},browser,notificar:m=>seen.messages.push(m)});
 return {seen,action};
}
test('novo download limpa aviso antigo e informa início usando o ID local',async()=>{
 const {seen,action}=scenario({ok:true,status:200,headers:{get:()=> 'application/pdf'},blob:async()=>new Blob(['%PDF-test'])});
 await action('download-audit-report',{dataset:{id:'84'}});
 assert.equal(seen.cleared,1);assert.equal(seen.clicked,1);
 assert.equal(seen.url,'/api/relatorio_auditoria.php?romaneio_id=84');
 assert.equal(seen.filename,'romaneio-84.pdf');
 assert.deepEqual(seen.messages,['Download do relatório iniciado.']);
});
test('recusa atual continua visível e não dispara download',async()=>{
 const {seen,action}=scenario({ok:false,status:409,text:async()=>JSON.stringify({error:'O relatório fica disponível após a finalização.'})});
 await action('download-audit-report',{dataset:{id:'84'}});
 assert.equal(seen.cleared,1);assert.equal(seen.clicked,0);
 assert.deepEqual(seen.messages,['O relatório fica disponível após a finalização.']);
});
