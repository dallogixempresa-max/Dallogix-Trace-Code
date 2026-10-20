-- Reenvios de integração usam a mesma resposta sem duplicar um romaneio.
CREATE TABLE IF NOT EXISTS api_romaneios_idempotencia (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  key_hash CHAR(64) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  romaneio_id BIGINT UNSIGNED NULL,
  response_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_api_romaneio_key (api_key_id, key_hash),
  KEY idx_api_romaneio_company_created (company_id, created_at),
  CONSTRAINT fk_api_romaneio_key FOREIGN KEY (api_key_id) REFERENCES chaves_api_integracao (id),
  CONSTRAINT fk_api_romaneio_company FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_api_romaneio_manifest FOREIGN KEY (romaneio_id) REFERENCES romaneios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
