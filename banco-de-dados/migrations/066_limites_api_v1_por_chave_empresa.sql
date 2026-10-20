-- Limites de consumo por chave e por empresa, separados entre leitura e escrita.
-- Cada escopo mantém apenas dois buckets ativos e a retenção remove os inativos.
CREATE TABLE IF NOT EXISTS limites_api_v1_chave (
  api_key_id BIGINT UNSIGNED NOT NULL,
  request_class ENUM('READ', 'WRITE') NOT NULL,
  window_key BIGINT UNSIGNED NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME(3) NOT NULL,
  PRIMARY KEY (api_key_id, request_class),
  KEY idx_api_rate_key_last_seen (last_seen_at),
  CONSTRAINT fk_api_rate_key FOREIGN KEY (api_key_id)
    REFERENCES chaves_api_integracao (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS limites_api_v1_empresa (
  company_id BIGINT UNSIGNED NOT NULL,
  request_class ENUM('READ', 'WRITE') NOT NULL,
  window_key BIGINT UNSIGNED NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME(3) NOT NULL,
  PRIMARY KEY (company_id, request_class),
  KEY idx_api_rate_company_last_seen (last_seen_at),
  CONSTRAINT fk_api_rate_company FOREIGN KEY (company_id)
    REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
