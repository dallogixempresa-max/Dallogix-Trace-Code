-- Credenciais por dispositivo, auditoria transacional e controles de operação.

CREATE TABLE IF NOT EXISTS dispositivos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  device_code VARCHAR(80) NOT NULL,
  device_type ENUM('CLP', 'SCANNER', 'SENSOR', 'CAMERA', 'SERVER') NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dispositivo_codigo (device_code),
  UNIQUE KEY uq_dispositivo_equipamento_tipo (equipment_id, device_type),
  KEY idx_dispositivo_empresa (company_id),
  CONSTRAINT fk_dispositivo_empresa FOREIGN KEY (company_id) REFERENCES empresas (id),
  CONSTRAINT fk_dispositivo_equipamento FOREIGN KEY (equipment_id) REFERENCES equipamentos (id)
);

DELIMITER //
CREATE PROCEDURE trace_ensure_migration_024_columns()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'status_dispositivos' AND column_name = 'device_id') THEN
    ALTER TABLE status_dispositivos ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'status_dispositivos' AND constraint_name = 'fk_status_dispositivo') THEN
    ALTER TABLE status_dispositivos ADD CONSTRAINT fk_status_dispositivo FOREIGN KEY (device_id) REFERENCES dispositivos (id) ON DELETE SET NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_comandos_clp' AND column_name = 'claimed_by_device_id') THEN
    ALTER TABLE solicitacoes_comandos_clp ADD COLUMN claimed_by_device_id BIGINT UNSIGNED NULL AFTER claimed_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'solicitacoes_comandos_clp' AND constraint_name = 'fk_command_claim_device') THEN
    ALTER TABLE solicitacoes_comandos_clp ADD CONSTRAINT fk_command_claim_device FOREIGN KEY (claimed_by_device_id) REFERENCES dispositivos (id) ON DELETE SET NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_captura_camera' AND column_name = 'claimed_by_device_id') THEN
    ALTER TABLE solicitacoes_captura_camera ADD COLUMN claimed_by_device_id BIGINT UNSIGNED NULL AFTER requested_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'solicitacoes_captura_camera' AND constraint_name = 'fk_camera_claim_device') THEN
    ALTER TABLE solicitacoes_captura_camera ADD CONSTRAINT fk_camera_claim_device FOREIGN KEY (claimed_by_device_id) REFERENCES dispositivos (id) ON DELETE SET NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'imagens' AND column_name = 'company_id') THEN
    ALTER TABLE imagens ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'must_change_password') THEN
    ALTER TABLE usuarios ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
  END IF;
END//
DELIMITER ;
CALL trace_ensure_migration_024_columns();
DROP PROCEDURE trace_ensure_migration_024_columns;

UPDATE imagens i
JOIN carregamentos c ON c.id = i.carregamento_id
SET i.company_id = c.company_id
WHERE i.company_id IS NULL;

ALTER TABLE imagens
  MODIFY COLUMN company_id BIGINT UNSIGNED NOT NULL;

DELIMITER //
CREATE PROCEDURE trace_ensure_migration_024_image_constraints()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'imagens' AND index_name = 'idx_image_company_captured') THEN
    ALTER TABLE imagens ADD KEY idx_image_company_captured (company_id, captured_at);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'imagens' AND constraint_name = 'fk_image_company') THEN
    ALTER TABLE imagens ADD CONSTRAINT fk_image_company FOREIGN KEY (company_id) REFERENCES empresas (id);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_migration_024_image_constraints();
DROP PROCEDURE trace_ensure_migration_024_image_constraints;

CREATE TABLE IF NOT EXISTS limites_login (
  identity_hash CHAR(64) PRIMARY KEY,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  violation_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

UPDATE usuarios
SET must_change_password = 1
WHERE password_hash IN (
  '$2y$10$ebOT1MqNyajFths8pCaJu.qE7MOSNMWkXYLan9LVzGSXFHIdgxK8C', -- nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- Hash público de demonstração local; força troca de senha para contas existentes.
  '$2y$12$rNW5syuIUQXWmnkzFzzCUOq7APYSVr.0iN9JIkPvCvoT0mfa/Bwm.' -- nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- Hash público de demonstração local; força troca de senha para contas existentes.
);

-- Consolida o estado atual da licença, preservando versões anteriores para consulta.
CREATE TABLE IF NOT EXISTS licencas_historico (
  original_id BIGINT UNSIGNED PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  plan_name VARCHAR(100) NOT NULL,
  billing_period ENUM('MENSAL') NOT NULL,
  status ENUM('ATIVA', 'BLOQUEADA') NOT NULL,
  blocked_at DATETIME NULL,
  blocked_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NULL
);

INSERT INTO licencas_historico
  (original_id, company_id, plan_name, billing_period, status, blocked_at, blocked_reason, created_at, updated_at)
SELECT l.id, l.company_id, l.plan_name, l.billing_period, l.status, l.blocked_at, l.blocked_reason, l.created_at, l.updated_at
FROM licencas l
JOIN (
  SELECT company_id, MAX(id) AS latest_id
  FROM licencas
  GROUP BY company_id
) latest ON latest.company_id = l.company_id AND latest.latest_id <> l.id
WHERE NOT EXISTS (
  SELECT 1 FROM licencas_historico h WHERE h.original_id = l.id
);

DELETE l
FROM licencas l
JOIN (
  SELECT company_id, MAX(id) AS latest_id
  FROM licencas
  GROUP BY company_id
) latest ON latest.company_id = l.company_id
WHERE l.id <> latest.latest_id;

DELIMITER //
CREATE PROCEDURE trace_ensure_migration_024_license_index()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'licencas' AND index_name = 'uq_license_company') THEN
    ALTER TABLE licencas ADD UNIQUE KEY uq_license_company (company_id);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_migration_024_license_index();
DROP PROCEDURE trace_ensure_migration_024_license_index;

-- Equipamentos existentes recebem a configuração padrão uma única vez. Novos
-- equipamentos são semeados pela aplicação durante a mesma transação de criação.
INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, modo, ordem)
SELECT e.company_id, e.id, 'INICIAR_CARREGAMENTO', 'LIGAR', 'VERDE', 'INCREMENTAL', 1
FROM equipamentos e
WHERE NOT EXISTS (
  SELECT 1 FROM acoes_dala a WHERE a.equipment_id = e.id AND a.comando = 'INICIAR_CARREGAMENTO'
);
INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, modo, ordem)
SELECT e.company_id, e.id, 'PAUSAR_CARREGAMENTO', 'PARAR', 'VERMELHO', 'INCREMENTAL', 2
FROM equipamentos e
WHERE NOT EXISTS (
  SELECT 1 FROM acoes_dala a WHERE a.equipment_id = e.id AND a.comando = 'PAUSAR_CARREGAMENTO'
);
INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, modo, ordem)
SELECT e.company_id, e.id, 'REVERSAO_ATIVAR', 'REVERSO', 'CINZA', 'DECREMENTAL', 3
FROM equipamentos e
WHERE NOT EXISTS (
  SELECT 1 FROM acoes_dala a WHERE a.equipment_id = e.id AND a.comando = 'REVERSAO_ATIVAR'
);
INSERT INTO acoes_dala (company_id, equipment_id, comando, rotulo, cor, modo, ordem)
SELECT e.company_id, e.id, 'REVERSAO_DESATIVAR', 'PARAR REVERSO', 'AMBAR', 'DIRETO', 4
FROM equipamentos e
WHERE NOT EXISTS (
  SELECT 1 FROM acoes_dala a WHERE a.equipment_id = e.id AND a.comando = 'REVERSAO_DESATIVAR'
);
INSERT INTO gatilhos_dala (company_id, equipment_id, evento, acao_id)
SELECT e.company_id, e.id, 'QUANTIDADE_PLANEJADA_ATINGIDA', a.id
FROM equipamentos e
JOIN acoes_dala a ON a.equipment_id = e.id AND a.comando = 'PAUSAR_CARREGAMENTO'
WHERE NOT EXISTS (
  SELECT 1 FROM gatilhos_dala g WHERE g.equipment_id = e.id AND g.evento = 'QUANTIDADE_PLANEJADA_ATINGIDA'
);
