-- Produto incorreto, excesso e retorno continuam auditados, mas não ocupam
-- unidades da meta enquanto a operação ainda está em andamento.
UPDATE eventos_sensor s
JOIN carregamentos c ON c.id = s.carregamento_id
JOIN leituras l ON l.sensor_event_id = s.id
              AND l.carregamento_id = s.carregamento_id
              AND l.company_id = c.company_id
SET s.conta_na_meta = 0
WHERE c.state IN ('PREPARANDO', 'CARREGANDO', 'PAUSADO', 'FINALIZANDO', 'EMERGENCIA')
  AND l.result NOT IN ('VALIDO', 'SEM_LEITURA')
  AND s.conta_na_meta = 1;

UPDATE leituras l
JOIN carregamentos c ON c.id = l.carregamento_id AND c.company_id = l.company_id
SET l.conta_na_meta = 0
WHERE c.state IN ('PREPARANDO', 'CARREGANDO', 'PAUSADO', 'FINALIZANDO', 'EMERGENCIA')
  AND l.result NOT IN ('VALIDO', 'SEM_LEITURA')
  AND l.conta_na_meta = 1;
