CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_company_code (company_id, code),
  CONSTRAINT fk_product_company FOREIGN KEY (company_id) REFERENCES companies (id)
);

CREATE TABLE IF NOT EXISTS product_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  barcode VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_barcode (barcode),
  CONSTRAINT fk_product_code_product FOREIGN KEY (product_id) REFERENCES products (id)
);

CREATE TABLE IF NOT EXISTS romaneios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  number VARCHAR(80) NOT NULL,
  scheduled_date DATE NOT NULL,
  status ENUM(
    'IMPORTADO',
    'AGUARDANDO',
    'EM_ANDAMENTO',
    'FINALIZADO',
    'CANCELADO'
  ) NOT NULL DEFAULT 'IMPORTADO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_romaneio_company_number (company_id, number),
  KEY idx_romaneio_date_status (scheduled_date, status),
  CONSTRAINT fk_romaneio_company FOREIGN KEY (company_id) REFERENCES companies (id)
);

CREATE TABLE IF NOT EXISTS romaneio_trucks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  romaneio_id BIGINT UNSIGNED NOT NULL,
  plate VARCHAR(20) NOT NULL,
  driver_name VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_romaneio_truck_plate (romaneio_id, plate),
  CONSTRAINT fk_truck_romaneio FOREIGN KEY (romaneio_id) REFERENCES romaneios (id)
);

CREATE TABLE IF NOT EXISTS romaneio_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  romaneio_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  truck_id BIGINT UNSIGNED NULL,
  planned_quantity INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_item_romaneio_product (romaneio_id, product_id),
  CONSTRAINT fk_item_romaneio FOREIGN KEY (romaneio_id) REFERENCES romaneios (id),
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_item_truck FOREIGN KEY (truck_id) REFERENCES romaneio_trucks (id)
);

CREATE TABLE IF NOT EXISTS carregamentos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  esteira_id BIGINT UNSIGNED NOT NULL,
  romaneio_id BIGINT UNSIGNED NOT NULL,
  truck_id BIGINT UNSIGNED NOT NULL,
  state ENUM(
    'AGUARDANDO',
    'PREPARANDO',
    'CARREGANDO',
    'PAUSADO',
    'FINALIZANDO',
    'FINALIZADO',
    'EMERGENCIA'
  ) NOT NULL DEFAULT 'AGUARDANDO',
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_loading_machine_state (esteira_id, state),
  CONSTRAINT fk_loading_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_loading_esteira FOREIGN KEY (esteira_id) REFERENCES esteiras (id),
  CONSTRAINT fk_loading_romaneio FOREIGN KEY (romaneio_id) REFERENCES romaneios (id),
  CONSTRAINT fk_loading_truck FOREIGN KEY (truck_id) REFERENCES romaneio_trucks (id)
);

CREATE TABLE IF NOT EXISTS sensor_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  esteira_id BIGINT UNSIGNED NOT NULL,
  event_uuid CHAR(36) NOT NULL,
  detected_at DATETIME(3) NOT NULL,
  debounce_ms INT UNSIGNED NOT NULL DEFAULT 400,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sensor_event_uuid (event_uuid),
  KEY idx_sensor_loading_time (carregamento_id, detected_at),
  CONSTRAINT fk_sensor_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_sensor_esteira FOREIGN KEY (esteira_id) REFERENCES esteiras (id)
);

CREATE TABLE IF NOT EXISTS leituras (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  sensor_event_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NULL,
  barcode VARCHAR(80) NULL,
  result ENUM(
    'VALIDO',
    'PRODUTO_INCORRETO',
    'SEM_LEITURA',
    'RETORNO',
    'EXCESSO'
  ) NOT NULL,
  read_at DATETIME(3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reading_loading_time (carregamento_id, read_at),
  CONSTRAINT fk_reading_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_reading_sensor FOREIGN KEY (sensor_event_id) REFERENCES sensor_events (id),
  CONSTRAINT fk_reading_product FOREIGN KEY (product_id) REFERENCES products (id)
);

CREATE TABLE IF NOT EXISTS ocorrencias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NULL,
  type VARCHAR(80) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  description TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_occurrence_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_occurrence_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_occurrence_user FOREIGN KEY (created_by) REFERENCES users (id)
);

CREATE TABLE IF NOT EXISTS retornos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  leitura_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_return_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_return_reading FOREIGN KEY (leitura_id) REFERENCES leituras (id)
);

CREATE TABLE IF NOT EXISTS imagens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  carregamento_id BIGINT UNSIGNED NULL,
  esteira_id BIGINT UNSIGNED NULL,
  reading_id BIGINT UNSIGNED NULL,
  path VARCHAR(500) NOT NULL,
  reason VARCHAR(80) NOT NULL,
  captured_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_image_loading FOREIGN KEY (carregamento_id) REFERENCES carregamentos (id),
  CONSTRAINT fk_image_esteira FOREIGN KEY (esteira_id) REFERENCES esteiras (id),
  CONSTRAINT fk_image_reading FOREIGN KEY (reading_id) REFERENCES leituras (id)
);

CREATE TABLE IF NOT EXISTS device_status (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  esteira_id BIGINT UNSIGNED NOT NULL,
  device_type ENUM('CLP', 'SCANNER', 'SENSOR', 'CAMERA', 'SERVER') NOT NULL,
  status ENUM('ONLINE', 'OFFLINE', 'ERRO', 'DESCONHECIDO') NOT NULL DEFAULT 'DESCONHECIDO',
  last_seen_at DATETIME NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device_status (esteira_id, device_type),
  CONSTRAINT fk_device_esteira FOREIGN KEY (esteira_id) REFERENCES esteiras (id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_company_created (company_id, created_at),
  CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE TABLE IF NOT EXISTS sync_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL,
  aggregate_type VARCHAR(100) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  status ENUM('PENDENTE', 'PROCESSANDO', 'ENVIADO', 'ERRO') NOT NULL DEFAULT 'PENDENTE',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sync_event_uuid (event_uuid),
  KEY idx_sync_status_available (status, available_at)
);
