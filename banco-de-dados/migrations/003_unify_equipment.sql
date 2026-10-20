CREATE TABLE IF NOT EXISTS equipments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_code VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_equipment_company_code (company_id, equipment_code),
  CONSTRAINT fk_equipment_company FOREIGN KEY (company_id) REFERENCES companies (id)
);

INSERT INTO
  equipments (id, company_id, equipment_code, name)
SELECT
  e.id,
  m.company_id,
  e.esteira_id,
  e.name
FROM
  esteiras e
  JOIN machines m ON m.id = e.machine_id
  LEFT JOIN equipments eq ON eq.id = e.id
WHERE
  eq.id IS NULL;

ALTER TABLE carregamentos
DROP FOREIGN KEY fk_loading_esteira;

ALTER TABLE sensor_events
DROP FOREIGN KEY fk_sensor_esteira;

ALTER TABLE imagens
DROP FOREIGN KEY fk_image_esteira;

ALTER TABLE device_status
DROP FOREIGN KEY fk_device_esteira;

ALTER TABLE carregamentos
CHANGE COLUMN esteira_id equipment_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE sensor_events
CHANGE COLUMN esteira_id equipment_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE imagens
CHANGE COLUMN esteira_id equipment_id BIGINT UNSIGNED NULL;

ALTER TABLE device_status
CHANGE COLUMN esteira_id equipment_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE carregamentos
ADD CONSTRAINT fk_loading_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id);

ALTER TABLE sensor_events
ADD CONSTRAINT fk_sensor_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id);

ALTER TABLE imagens
ADD CONSTRAINT fk_image_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id);

ALTER TABLE device_status
ADD CONSTRAINT fk_device_equipment FOREIGN KEY (equipment_id) REFERENCES equipments (id);

DROP TABLE esteiras;

DROP TABLE machines;
