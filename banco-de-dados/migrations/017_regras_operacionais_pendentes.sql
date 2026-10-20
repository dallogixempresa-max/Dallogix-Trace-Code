-- Regras operacionais previstas no escopo técnico.
-- Não habilita escrita direta no CLP; somente registra estado e evidência local.
ALTER TABLE carregamentos
  ADD COLUMN stop_reason VARCHAR(120) NULL,
  ADD COLUMN operator_alert VARCHAR(255) NULL,
  ADD COLUMN finish_justification VARCHAR(1000) NULL;

ALTER TABLE retornos
  ADD COLUMN reason VARCHAR(500) NULL,
  ADD COLUMN created_by BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_return_user FOREIGN KEY (created_by) REFERENCES users (id);

ALTER TABLE sensor_events
  ADD KEY idx_sensor_equipment_time (equipment_id, detected_at);
