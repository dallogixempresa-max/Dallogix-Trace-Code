CREATE TABLE IF NOT EXISTS vinculos_disparo_leitor (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  sensor_event_id BIGINT UNSIGNED NOT NULL,
  offset_ms INT NOT NULL COMMENT 'captura - tempo de leitura do Keyence - inicio do pulso M2052, em ms',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_vinculos_disparo_passagem (sensor_event_id),
  KEY idx_vinculos_disparo_carregamento (carregamento_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Calibra a ligacao do resultado do leitor ao disparo (pulso M2052)';
