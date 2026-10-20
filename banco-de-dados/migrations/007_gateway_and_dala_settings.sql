ALTER TABLE equipments
ADD COLUMN plc_ip VARCHAR(45) NULL AFTER name,
ADD COLUMN plc_port SMALLINT UNSIGNED NULL DEFAULT 502 AFTER plc_ip,
ADD COLUMN external_port SMALLINT UNSIGNED NULL AFTER plc_port,
ADD COLUMN plc_protocol ENUM('MODBUS_TCP', 'MODBUS_RTU') NOT NULL DEFAULT 'MODBUS_TCP' AFTER external_port;

CREATE TABLE IF NOT EXISTS company_settings (
  company_id BIGINT UNSIGNED PRIMARY KEY,
  gateway_public_ip VARCHAR(255) NULL,
  sync_remote_url VARCHAR(500) NULL,
  pdf_field_mapping JSON NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id)
);
