-- O monitoramento conta eventos por carregamento e equipamento a cada atualização.
-- O índice composto permite localizar e contar apenas os eventos da máquina ativa.
SET @trace_sensor_event_index_sql = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'eventos_sensor'
      AND index_name = 'idx_sensor_loading_equipment'
  ),
  'SELECT 1',
  'ALTER TABLE eventos_sensor ADD KEY idx_sensor_loading_equipment (carregamento_id, equipment_id)'
);
PREPARE trace_sensor_event_index_stmt FROM @trace_sensor_event_index_sql;
EXECUTE trace_sensor_event_index_stmt;
DEALLOCATE PREPARE trace_sensor_event_index_stmt;
