import assert from 'node:assert/strict';
import { readFile, access } from 'node:fs/promises';
import test from 'node:test';
const modules = [
  '/telas/operacao/selecao-dala.js',
  '/telas/operacao/itens-romaneio.js',
  '/js/funcoes/controles-operacao.js',
  '/js/funcoes/progresso-operacao.js',
  '/js/controladores/executor-comandos-operacao.js',
];
test('módulos extraídos da operação existem e são pré-carregados para uso offline',async()=>{
  const sw=await readFile(new URL('../interface/service-worker.js',import.meta.url),'utf8');
  for(const module of modules){
    await access(new URL('../interface'+module,import.meta.url));
    assert.ok(sw.includes(JSON.stringify(module)),module+' ausente do cache offline');
  }
});
