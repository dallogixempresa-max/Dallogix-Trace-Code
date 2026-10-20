-- A ativação pertence à empresa, não a uma Dala específica.

SET NAMES utf8mb4;

ALTER TABLE empresas
  ADD COLUMN activation_code_hash CHAR(64) NULL AFTER login_domain,
  ADD COLUMN activation_code_preview CHAR(4) NULL AFTER activation_code_hash,
  ADD COLUMN activation_code_created_at DATETIME NULL AFTER activation_code_preview,
  ADD COLUMN remote_company_id BIGINT UNSIGNED NULL AFTER activation_code_created_at,
  ADD UNIQUE KEY uq_empresa_activation_hash (activation_code_hash),
  ADD UNIQUE KEY uq_empresa_remote_id (remote_company_id);

DROP TABLE IF EXISTS ativacoes_maquina;

CREATE TABLE IF NOT EXISTS instalacoes_locais (
  id TINYINT UNSIGNED PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  remote_company_id BIGINT UNSIGNED NOT NULL,
  company_name VARCHAR(160) NOT NULL,
  login_domain VARCHAR(190) NOT NULL,
  activated_at DATETIME NOT NULL,
  CONSTRAINT fk_installation_company FOREIGN KEY (company_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
