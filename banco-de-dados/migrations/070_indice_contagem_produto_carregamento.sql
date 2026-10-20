-- A contagem por produto ocorre durante cada leitura do scanner.
-- O índice composto evita varrer todas as leituras válidas do carregamento.
SET @trace_product_count_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leituras'
      AND index_name = 'idx_reading_company_loading_result_product'
  ),
  'SELECT 1',
  'ALTER TABLE leituras ADD KEY idx_reading_company_loading_result_product (company_id, carregamento_id, result, product_id)'
);
PREPARE trace_product_count_index_stmt FROM @trace_product_count_index_sql;
EXECUTE trace_product_count_index_stmt;
DEALLOCATE PREPARE trace_product_count_index_stmt;
