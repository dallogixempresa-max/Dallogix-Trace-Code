-- Índices para manter a fila rápida quando há muitos eventos pendentes.
-- A primeira chave preserva a ordem por agregado usada pelo worker; a segunda
-- acelera a descoberta de empresas e a reserva por status e disponibilidade.

SET @trace_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'fila_sincronizacao'
      AND index_name = 'idx_fila_agregado_ordem'
  ),
  'SELECT 1',
  'ALTER TABLE fila_sincronizacao ADD KEY idx_fila_agregado_ordem (company_id, aggregate_type, aggregate_id, status, id)'
);
PREPARE trace_index_stmt FROM @trace_index_sql;
EXECUTE trace_index_stmt;
DEALLOCATE PREPARE trace_index_stmt;

SET @trace_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'fila_sincronizacao'
      AND index_name = 'idx_fila_status_disponivel_empresa'
  ),
  'SELECT 1',
  'ALTER TABLE fila_sincronizacao ADD KEY idx_fila_status_disponivel_empresa (status, available_at, company_id, id)'
);
PREPARE trace_index_stmt FROM @trace_index_sql;
EXECUTE trace_index_stmt;
DEALLOCATE PREPARE trace_index_stmt;
