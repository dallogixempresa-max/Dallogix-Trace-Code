-- A aplicação da migration não é transacional: se uma etapa posterior falhar,
-- as colunas e os índices já criados precisam ser ignorados na próxima tentativa.
SET @trace_079_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'eventos_sensor'
     AND column_name = 'event_at_epoch_ms') = 0,
  'ALTER TABLE eventos_sensor ADD COLUMN event_at_epoch_ms BIGINT UNSIGNED NULL AFTER device_detected_at',
  'SELECT 1'
);
PREPARE trace_079_stmt FROM @trace_079_sql;
EXECUTE trace_079_stmt;
DEALLOCATE PREPARE trace_079_stmt;

SET @trace_079_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'eventos_sensor'
     AND column_name = 'conta_na_meta_origem') = 0,
  'ALTER TABLE eventos_sensor ADD COLUMN conta_na_meta_origem TINYINT(1) NOT NULL DEFAULT 1 AFTER event_at_epoch_ms',
  'SELECT 1'
);
PREPARE trace_079_stmt FROM @trace_079_sql;
EXECUTE trace_079_stmt;
DEALLOCATE PREPARE trace_079_stmt;

SET @trace_079_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'eventos_sensor'
     AND column_name = 'conta_na_meta') = 0,
  'ALTER TABLE eventos_sensor ADD COLUMN conta_na_meta TINYINT(1) NOT NULL DEFAULT 1 AFTER conta_na_meta_origem',
  'SELECT 1'
);
PREPARE trace_079_stmt FROM @trace_079_sql;
EXECUTE trace_079_stmt;
DEALLOCATE PREPARE trace_079_stmt;

SET @trace_079_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'eventos_sensor'
     AND index_name = 'idx_sensor_loading_counted') = 0,
  'ALTER TABLE eventos_sensor ADD KEY idx_sensor_loading_counted (carregamento_id, equipment_id, conta_na_meta, event_at_epoch_ms, id)',
  'SELECT 1'
);
PREPARE trace_079_stmt FROM @trace_079_sql;
EXECUTE trace_079_stmt;
DEALLOCATE PREPARE trace_079_stmt;

SET @trace_079_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'leituras'
     AND column_name = 'conta_na_meta') = 0,
  'ALTER TABLE leituras ADD COLUMN conta_na_meta TINYINT(1) NOT NULL DEFAULT 1 AFTER result',
  'SELECT 1'
);
PREPARE trace_079_stmt FROM @trace_079_sql;
EXECUTE trace_079_stmt;
DEALLOCATE PREPARE trace_079_stmt;

CREATE TABLE IF NOT EXISTS pausas_carregamento (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  paused_at_epoch_ms BIGINT UNSIGNED NOT NULL,
  resumed_at_epoch_ms BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loading_pause_start (carregamento_id, paused_at_epoch_ms),
  KEY idx_loading_pause_window (carregamento_id, paused_at_epoch_ms, resumed_at_epoch_ms),
  CONSTRAINT fk_loading_pause_company FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_loading_pause_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id)
);
