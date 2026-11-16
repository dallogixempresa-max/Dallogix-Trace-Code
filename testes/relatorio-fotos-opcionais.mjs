import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('PDF é emitido sem fotos, com foto parcial, tardia, trocada ou vínculo inválido e mantém recuperação', () => {
  const result = spawnSync('php', [new URL('./relatorio-fotos-opcionais.php', import.meta.url).pathname], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || String(result.error));
  assert.equal(result.stdout.trim(), 'ok');
});

test('ausência de foto não impede transporte e fica somente no log, sem espaço no PDF', async () => {
  for (const name of ['servidor/src/Aplicacao/ServicoSincronizacao.php', 'servidor/api/sincronizacao/receber_relatorio_auditoria.php']) {
    const source = await readFile(new URL('../' + name, import.meta.url), 'utf8');
    assert.match(source, /\$evidenceCount < 0 \|\| \(int\) \$evidenceCount > \(int\) \$evidenceNoReadCount/);
    assert.doesNotMatch(source, /\$evidenceCount < \(int\) \$evidenceNoReadCount/);
    assert.match(source, /hash_equals/);
  }
  const report = await readFile(new URL('../servidor/api/relatorios/relatorio_auditoria.php', import.meta.url), 'utf8');
  assert.doesNotMatch(report, /O relatório aguarda|Aguarde o envio das imagens|missing_evidence_count/);
  assert.match(report, /registrar_log_erro\(new RuntimeException\(\$warning\), "relatorio_pendencia_evidencia"\)/);
  assert.doesNotMatch(report, /evidence_label|Pendências de evidência nesta emissão|O relatório só é liberado|"evidence_warnings" =>/);
  assert.match(report, /"pending_passage_count" =>/);
});
