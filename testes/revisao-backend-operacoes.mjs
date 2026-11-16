import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
test('cancelamento, encerramento e resumo usam o mesmo validador da fila',async()=>{
  for(const route of ['romaneios/romaneios.php','operacoes/encerrar_carregamento.php','operacoes/resumo_carregamento.php']) {
    const source=await readFile(new URL('../servidor/api/'+route,import.meta.url),'utf8');
    assert.match(source,/RepositorioFilaSensor\(\$pdo\)/,route);
    assert.match(source,/ValidadorFilaSensor::pendentes\(/,route);
    assert.doesNotMatch(source,/json_decode\(\$sensor/,route);
  }
  const result=spawnSync('php',[new URL('./fila-sensor.php',import.meta.url).pathname],{encoding:'utf8'});
  assert.equal(result.status,0,result.stderr||String(result.error));
});
test('cargas sincronizadas sem Dala respeitam pausa, emergência e proteção de exclusão',()=>{
  const result=spawnSync('php',[new URL('./vinculo-dala.php',import.meta.url).pathname],{encoding:'utf8'});
  assert.equal(result.status,0,result.stderr||String(result.error));
});
