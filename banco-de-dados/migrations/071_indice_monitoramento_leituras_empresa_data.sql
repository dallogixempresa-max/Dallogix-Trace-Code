-- O snapshot do monitoramento conta leituras recentes em consultas frequentes.
-- Empresa e data reduzem o intervalo lido; carregamento e resultado cobrem
-- a junção e a agregação sem buscar essas colunas fora do índice.
SET @trace_monitoring_readings_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leituras'
      AND index_name = 'idx_reading_company_time_loading_result'
  ),
  'SELECT 1',
  'ALTER TABLE leituras ADD KEY idx_reading_company_time_loading_result (company_id, read_at, carregamento_id, result)'
);
PREPARE trace_monitoring_readings_index_stmt FROM @trace_monitoring_readings_index_sql;
EXECUTE trace_monitoring_readings_index_stmt;
DEALLOCATE PREPARE trace_monitoring_readings_index_stmt;
