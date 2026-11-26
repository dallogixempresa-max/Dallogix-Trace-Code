CREATE TABLE IF NOT EXISTS leituras_sem_passagem (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  carregamento_id BIGINT UNSIGNED NOT NULL,
  barcode VARCHAR(128) NULL COMMENT 'Código recebido do leitor; NULL quando foi ERROR (falha de leitura)',
  no_read TINYINT(1) NOT NULL DEFAULT 0,
  motivo VARCHAR(32) NOT NULL COMMENT 'SEM_PASSAGEM, ESTEIRA_PARADA ou DESCARTADA_TELA',
  captured_at DATETIME(3) NOT NULL COMMENT 'Hora da captura na tela, em UTC',
  user_id BIGINT UNSIGNED NULL,
  event_uuid VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_leituras_sem_passagem_evento (event_uuid),
  KEY idx_leituras_sem_passagem_carregamento (carregamento_id, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resultados do leitor que não foram ligados a nenhuma passagem do sensor';
