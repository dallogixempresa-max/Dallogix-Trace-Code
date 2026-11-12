ALTER TABLE eventos_sensor
  ADD COLUMN event_at_epoch_ms BIGINT UNSIGNED NULL AFTER device_detected_at,
  ADD COLUMN conta_na_meta_origem TINYINT(1) NOT NULL DEFAULT 1 AFTER event_at_epoch_ms,
  ADD COLUMN conta_na_meta TINYINT(1) NOT NULL DEFAULT 1 AFTER conta_na_meta_origem,
  ADD KEY idx_sensor_loading_counted (carregamento_id, equipment_id, conta_na_meta, event_at_epoch_ms, id);

ALTER TABLE leituras
  ADD COLUMN conta_na_meta TINYINT(1) NOT NULL DEFAULT 1 AFTER result;

CREATE TABLE pausas_carregamento (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  paused_at_epoch_ms BIGINT UNSIGNED NOT NULL,
  resumed_at_epoch_ms BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loading_pause_start (carregamento_id, paused_at_epoch_ms),
  KEY idx_loading_pause_window (carregamento_id, paused_at_epoch_ms, resumed_at_epoch_ms),
  CONSTRAINT fk_loading_pause_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_loading_pause_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id)
);
