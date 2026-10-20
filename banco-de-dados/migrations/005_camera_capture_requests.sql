CREATE TABLE IF NOT EXISTS camera_capture_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sensor_event_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  status ENUM('PENDENTE', 'CAPTURANDO', 'CAPTURADA', 'ERRO') NOT NULL DEFAULT 'PENDENTE',
  requested_at DATETIME(3) NOT NULL,
  captured_at DATETIME(3) NULL,
  image_path VARCHAR(500) NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_camera_request_sensor (sensor_event_id),
  KEY idx_camera_request_status (status, requested_at),
  CONSTRAINT fk_camera_request_sensor FOREIGN KEY (sensor_event_id) REFERENCES sensor_events (id),
  CONSTRAINT fk_camera_request_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_camera_request_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id)
);
