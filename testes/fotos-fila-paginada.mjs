import test from 'node:test';
import assert from 'node:assert/strict';
import { loadPendingImagePages, matchUniquePendingImage } from '../integracoes/keyence-worker/keyence-core.mjs';
function paged(rows) {
 const calls=[];
 return {calls,fetch:async after=>{
  calls.push(after);
  const page=rows.filter(r=>r.id>after).slice(0,100);
  return {data:page,meta:{next_after_id:page.length===100?page.at(-1).id:null}};
 }};
}
test('foto recente encontra seu incidente mesmo com mais de 100 erros antigos',async()=>{
 const rows=Array.from({length:215},(_,i)=>({id:i+1,status:'ERRO',image_expected_at_epoch_ms:1000+i}));
 rows.push({id:216,status:'PENDENTE',image_expected_at_epoch_ms:100000});
 const api=paged(rows),requests=await loadPendingImagePages(api.fetch);
 assert.equal(requests.length,216);assert.deepEqual(api.calls,[0,100,200]);
 assert.equal(matchUniquePendingImage(100250,requests,1500,'image_expected_at_epoch_ms').request.id,216);
});
test('candidatos em páginas diferentes continuam ambíguos, sem escolher o saco errado',async()=>{
 const rows=Array.from({length:101},(_,i)=>({id:i+1,status:'ERRO',image_expected_at_epoch_ms:i<99?1000:100000}));
 const api=paged(rows),requests=await loadPendingImagePages(api.fetch);
 const match=matchUniquePendingImage(100000,requests,1500,'image_expected_at_epoch_ms');
 assert.equal(match.request,null);assert.equal(match.reason,'solicitacoes_ambiguas');
});
test('falha na segunda página não entrega uma lista incompleta para associação',async()=>{
 await assert.rejects(loadPendingImagePages(async after=>{
  if(after)throw Error('API indisponível');
  return {data:Array.from({length:100},(_,i)=>({id:i+1})),meta:{next_after_id:100}};
 }),/API indisponível/);
});
test('cursor inválido interrompe a leitura, sem repetir indefinidamente',async()=>{
 await assert.rejects(loadPendingImagePages(async()=>({data:[{id:1}],meta:{next_after_id:0}})),/sem avanço/);
});
test('recuperação antiga considera foto já capturada para não vincular ao saco seguinte',()=>{
 const requests=[{id:1,status:'CAPTURADA',image_expected_at_epoch_ms:100000},{id:2,status:'ERRO',image_expected_at_epoch_ms:100800}];
 assert.equal(matchUniquePendingImage(100100,requests,1500,'image_expected_at_epoch_ms').reason,'solicitacoes_ambiguas');
 assert.equal(matchUniquePendingImage(100100,requests.slice(0,1),1500,'image_expected_at_epoch_ms').request,null);
});
