import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const auditoria = await readFile(
  new URL("../servidor/api/relatorios/relatorio_auditoria.php", import.meta.url),
  "utf8",
);
const encerrar = await readFile(
  new URL("../servidor/api/operacoes/encerrar_carregamento.php", import.meta.url),
  "utf8",
);
const resumo = await readFile(
  new URL("../servidor/api/operacoes/resumo_carregamento.php", import.meta.url),
  "utf8",
);

test("a query de falhas de leitura inclui product_id e nome do produto", () => {
  // A auditoria precisa mostrar se a SEM_LEITURA tinha produto identificado
  // ou se é um caso de romaneio múltiplo sem leitura (product_id NULL).
  assert.match(auditoria, /l\.product_id/);
  assert.match(auditoria, /p\.name AS product_name/);
  assert.match(auditoria, /LEFT JOIN produtos p ON p\.id = l\.product_id/);
});

test("a tabela de falhas exibe coluna Produto com fallback 'Não identificado'", () => {
  // Produto NULL deve aparecer como texto explícito, não como vazio ou travessão.
  assert.match(auditoria, /\$row\["product_name"\] \?\? "Não identificado"/);
  assert.match(auditoria, /"Data\/hora", "Produto", "Passagem", "Evidência da imagem"/);
});

test("o resumo da operação exibe 'Sacas não identificadas' somente quando há NULLs", () => {
  // O cartão só aparece quando há produto_id NULL — romaneios de produto
  // único nunca têm esse caso e não devem exibir a chave.
  assert.match(auditoria, /no_reads_sem_produto/);
  assert.match(auditoria, /\$operationSummary\["no_reads_sem_produto"\]/);
  assert.match(auditoria, /Sacas não identificadas/);
  // Condicional: só exibe quando > 0
  assert.match(auditoria, /\(\$operationSummary\["no_reads_sem_produto"\] \?\? 0\) > 0/);
});

test("a seção 'Conferência dos itens' inclui nota sobre sacas sem produto", () => {
  // O expedidor precisa saber que a divergência de itens pode incluir sacas
  // não atribuídas, não apenas erros de leitura por produto conhecido.
  assert.match(auditoria, /Sacas não identificadas/);
  assert.match(
    auditoria,
    /passagens constam no total detectado pelo sensor M2052, mas não são atribuídas a nenhum item/,
  );
});

test("o encerramento retorna leituras_sem_produto no payload de resposta", () => {
  // O front pode exibir o alerta imediatamente após o encerramento sem
  // precisar fazer polling do resumo.
  assert.match(encerrar, /leituras_sem_produto/);
  assert.match(encerrar, /result = 'SEM_LEITURA' AND conta_na_meta = 1 AND product_id IS NULL/);
  assert.match(encerrar, /"leituras_sem_produto" => \$leiturasSemProduto/);
});

test("resumo_carregamento expõe leituras_sem_produto durante o carregamento", () => {
  // O painel de operação acompanha estes dados durante a operação;
  // a métrica precisa estar disponível antes do encerramento.
  assert.match(resumo, /leituras_sem_produto/);
  assert.match(resumo, /result = 'SEM_LEITURA' AND conta_na_meta = 1 AND product_id IS NULL/);
});
