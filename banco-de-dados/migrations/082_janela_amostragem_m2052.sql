ALTER TABLE eventos_sensor
ADD COLUMN modbus_passage_from_epoch_ms BIGINT UNSIGNED NULL COMMENT 'Epoch UTC da ultima consulta anterior; usado na associacao com o leitor';
