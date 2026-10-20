-- Guarda o último heartbeat da instalação local no servidor central.
-- O registro é por empresa porque o PC industrial é a ponte da instalação;
-- ele não deve ser confundido com o status individual de uma Dala.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS status_pc_industrial (
  company_id BIGINT UNSIGNED NOT NULL,
  status ENUM('ONLINE', 'OFFLINE', 'ERRO', 'DESCONHECIDO') NOT NULL DEFAULT 'DESCONHECIDO',
  last_seen_at DATETIME(3) NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME(3) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (company_id),
  KEY idx_status_pc_industrial_last_seen (last_seen_at),
  CONSTRAINT fk_status_pc_industrial_empresa
    FOREIGN KEY (company_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
