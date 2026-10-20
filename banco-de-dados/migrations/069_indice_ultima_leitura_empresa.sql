-- O snapshot do monitoramento é consultado com frequência no PC industrial.
-- Este índice permite buscar a leitura mais recente dentro de uma empresa
-- sem percorrer leituras pertencentes a outras empresas.
SET @trace_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leituras'
      AND index_name = 'idx_reading_company_latest'
  ),
  'SELECT 1',
  'ALTER TABLE leituras ADD KEY idx_reading_company_latest (company_id, id)'
);
PREPARE trace_index_stmt FROM @trace_index_sql;
EXECUTE trace_index_stmt;
DEALLOCATE PREPARE trace_index_stmt;
