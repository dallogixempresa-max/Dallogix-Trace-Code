-- Credenciais dedicadas para a API externa, sempre vinculadas a uma empresa.
CREATE TABLE IF NOT EXISTS chaves_api_integracao (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  token_prefix VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes_json JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_used_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  UNIQUE KEY uq_api_key_hash (token_hash),
  KEY idx_api_key_company_status (company_id, revoked_at, id),
  CONSTRAINT fk_api_key_company FOREIGN KEY (company_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
