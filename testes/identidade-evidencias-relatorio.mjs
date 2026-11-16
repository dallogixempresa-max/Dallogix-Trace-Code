import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import {readFile} from 'node:fs/promises';
test('relatório recusa fotos de outra passagem, carga, empresa, Dala ou motivo e detecta troca com totais iguais',()=>{
 const result=spawnSync('php',[new URL('./identidade-evidencias-relatorio.php',import.meta.url).pathname],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr||String(result.error));assert.equal(result.stdout.trim(),'ok');
});
test('identidade por UUID e hash acompanha publicação, envio, recebimento e validação do PDF',async()=>{
 for(const path of ['servidor/src/Aplicacao/ServicoPublicacaoRelatoriosAuditoria.php','servidor/src/Aplicacao/ServicoSincronizacao.php','servidor/api/sincronizacao/receber_relatorio_auditoria.php']){
  const source=await readFile(new URL('../'+path,import.meta.url),'utf8');
  assert.match(source,/evidence_identity_sha256|HTTP_X_TRACE_REPORT_EVIDENCE_IDENTITY/,path);
 }
 const report=await readFile(new URL('../servidor/api/relatorios/relatorio_auditoria.php',import.meta.url),'utf8');
 assert.match(report,/ConferidorEvidenciasRelatorio::vincular/);
 assert.match(report,/ConferidorEvidenciasRelatorio::manifestoCorresponde/);
 assert.match(report,/!\$isLocalReport/);
});
