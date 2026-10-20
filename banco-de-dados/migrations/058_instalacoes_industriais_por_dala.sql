-- Registra cada PC industrial separadamente e vincula uma Dala a uma
-- instalação. A ativação e o heartbeat deixam de ser identificados apenas
-- pela empresa.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS instalacoes_industriais (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NULL,
  auto_link_first_dala TINYINT(1) NOT NULL DEFAULT 1,
  name VARCHAR(160) NOT NULL,
  activation_code VARCHAR(34) NULL,
  activation_code_hash CHAR(64) NULL,
  activation_code_preview CHAR(4) NULL,
  activation_code_created_at DATETIME NULL,
  activation_code_expires_at DATETIME NULL,
  activation_code_used_at DATETIME NULL,
  sync_token_hash CHAR(64) NULL,
  status ENUM('ONLINE', 'OFFLINE', 'ERRO', 'DESCONHECIDO') NOT NULL DEFAULT 'DESCONHECIDO',
  last_seen_at DATETIME(3) NULL,
  last_delivered_queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  details JSON NULL,
  activated_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME(3) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_instalacao_industrial_equipamento (equipment_id),
  UNIQUE KEY uq_instalacao_industrial_codigo (activation_code_hash),
  UNIQUE KEY uq_instalacao_industrial_token (sync_token_hash),
  KEY idx_instalacao_industrial_empresa (company_id, status, last_seen_at),
  CONSTRAINT fk_instalacao_industrial_empresa
    FOREIGN KEY (company_id) REFERENCES empresas (id) ON DELETE CASCADE,
  CONSTRAINT fk_instalacao_industrial_equipamento
    FOREIGN KEY (equipment_id) REFERENCES equipamentos (id) ON DELETE SET NULL,
  CONSTRAINT fk_instalacao_industrial_criador
    FOREIGN KEY (created_by) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra a ativação antiga sem adivinhar entre várias Dalas. Quando existe
-- uma única Dala, o vínculo é direto; quando existem várias, exige revisão
-- manual pela Master. Empresa sem Dala pode vincular a primeira após ativar.
INSERT INTO instalacoes_industriais (
  company_id,
  equipment_id,
  auto_link_first_dala,
  name,
  activation_code,
  activation_code_hash,
  activation_code_preview,
  activation_code_created_at,
  activation_code_expires_at,
  activation_code_used_at,
  sync_token_hash,
  status,
  last_seen_at,
  details,
  activated_at
)
SELECT
  c.id,
  CASE
    WHEN (SELECT COUNT(*) FROM equipamentos e WHERE e.company_id = c.id) = 1
      THEN (SELECT MIN(e.id) FROM equipamentos e WHERE e.company_id = c.id)
    ELSE NULL
  END,
  CASE
    WHEN (SELECT COUNT(*) FROM equipamentos e WHERE e.company_id = c.id) > 1 THEN 0
    ELSE 1
  END,
  CONCAT('PC industrial legado ', c.id),
  c.activation_code,
  c.activation_code_hash,
  c.activation_code_preview,
  c.activation_code_created_at,
  c.activation_code_expires_at,
  c.activation_code_used_at,
  c.installation_token_hash,
  COALESCE(s.status, 'DESCONHECIDO'),
  s.last_seen_at,
  s.details,
  CASE WHEN c.installation_token_hash IS NOT NULL THEN s.updated_at ELSE NULL END
FROM empresas c
LEFT JOIN status_pc_industrial s ON s.company_id = c.id
WHERE (
    c.activation_code_hash IS NOT NULL
    OR c.installation_token_hash IS NOT NULL
    OR s.company_id IS NOT NULL
  )
  AND NOT EXISTS (
    SELECT 1 FROM instalacoes_industriais existing
    WHERE existing.company_id = c.id
      AND existing.name = CONCAT('PC industrial legado ', c.id)
  );

DELIMITER //
CREATE PROCEDURE trace_ensure_remote_installation_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND column_name = 'remote_installation_id'
  ) THEN
    ALTER TABLE instalacoes_locais
      ADD COLUMN remote_installation_id BIGINT UNSIGNED NULL AFTER remote_company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND column_name = 'remote_equipment_id'
  ) THEN
    ALTER TABLE instalacoes_locais
      ADD COLUMN remote_equipment_id BIGINT UNSIGNED NULL AFTER remote_installation_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_industriais'
      AND column_name = 'last_delivered_queue_id'
  ) THEN
    ALTER TABLE instalacoes_industriais
      ADD COLUMN last_delivered_queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_seen_at;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND index_name = 'idx_instalacao_local_remote_installation'
  ) THEN
    ALTER TABLE instalacoes_locais
      ADD KEY idx_instalacao_local_remote_installation (remote_installation_id, remote_equipment_id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND column_name = 'remote_installation_id'
  ) THEN
    ALTER TABLE romaneios
      ADD COLUMN remote_installation_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'carregamentos'
      AND column_name = 'remote_installation_id'
  ) THEN
    ALTER TABLE carregamentos
      ADD COLUMN remote_installation_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND column_name = 'installation_scope_id'
  ) THEN
    ALTER TABLE romaneios
      ADD COLUMN installation_scope_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (COALESCE(remote_installation_id, 0)) STORED
        AFTER remote_installation_id;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_company_number'
  ) THEN
    ALTER TABLE romaneios DROP INDEX uq_romaneio_company_number;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_company_installation_number'
  ) THEN
    ALTER TABLE romaneios
      ADD UNIQUE KEY uq_romaneio_company_installation_number
        (company_id, installation_scope_id, number);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_empresa_remoto'
  ) THEN
    ALTER TABLE romaneios DROP INDEX uq_romaneio_empresa_remoto;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_installation_remote'
  ) THEN
    ALTER TABLE romaneios
      ADD UNIQUE KEY uq_romaneio_installation_remote (company_id, remote_installation_id, remote_romaneio_id);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'carregamentos'
      AND index_name = 'uq_carregamento_empresa_remoto'
  ) THEN
    ALTER TABLE carregamentos DROP INDEX uq_carregamento_empresa_remoto;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'carregamentos'
      AND index_name = 'uq_carregamento_installation_remote'
  ) THEN
    ALTER TABLE carregamentos
      ADD UNIQUE KEY uq_carregamento_installation_remote (company_id, remote_installation_id, remote_carregamento_id);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_remote_installation_columns();
DROP PROCEDURE trace_ensure_remote_installation_columns;

-- As linhas remotas antigas pertencem ao único PC legado da empresa, quando
-- esse vínculo é inequívoco. Colisões ambíguas permanecem sem origem técnica.
UPDATE romaneios r
JOIN instalacoes_industriais p ON p.company_id = r.company_id
SET r.remote_installation_id = p.id
WHERE r.remote_romaneio_id IS NOT NULL
  AND r.remote_installation_id IS NULL
  AND (SELECT COUNT(*) FROM instalacoes_industriais p2 WHERE p2.company_id = r.company_id) = 1;

UPDATE carregamentos c
JOIN instalacoes_industriais p ON p.company_id = c.company_id
SET c.remote_installation_id = p.id
WHERE c.remote_carregamento_id IS NOT NULL
  AND c.remote_installation_id IS NULL
  AND (SELECT COUNT(*) FROM instalacoes_industriais p2 WHERE p2.company_id = c.company_id) = 1;
