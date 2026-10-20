-- Migration histórica restaurada para manter o encadeamento do banco.
-- A ativação atual pertence à empresa e a migration 035 substitui esta tabela
-- por instalacoes_locais; a tabela abaixo precisa existir nesta etapa para que
-- instalações novas e bancos já migrados tenham o mesmo histórico.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ativacoes_maquina (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  code_preview CHAR(4) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_activation_code_hash (code_hash),
  KEY idx_activation_equipment (equipment_id, used_at, revoked_at, expires_at),
  CONSTRAINT fk_activation_company FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_activation_equipment FOREIGN KEY (equipment_id) REFERENCES equipamentos (id),
  CONSTRAINT fk_activation_user FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
