-- A quantidade planejada gera aviso e permite continuar contando excedentes.
-- A parada da esteira é sempre uma ação explícita do operador.
UPDATE gatilhos_dala
SET ativo = 0,
    atualizado_em = CURRENT_TIMESTAMP
WHERE evento = 'QUANTIDADE_PLANEJADA_ATINGIDA'
  AND ativo = 1;
