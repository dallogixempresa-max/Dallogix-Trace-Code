-- Repara instalações que registraram a migration bidirecional antes de a
-- alteração chegar ao banco. A rotina é idempotente para não interromper o
-- deploy nem apagar o vínculo de uma instalação já ativada.

SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_repair_sync_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'equipamentos'
      AND column_name = 'remote_equipment_id'
  ) THEN
    ALTER TABLE equipamentos ADD COLUMN remote_equipment_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND column_name = 'remote_romaneio_id'
  ) THEN
    ALTER TABLE romaneios ADD COLUMN remote_romaneio_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneio_caminhoes'
      AND column_name = 'remote_truck_id'
  ) THEN
    ALTER TABLE romaneio_caminhoes ADD COLUMN remote_truck_id BIGINT UNSIGNED NULL AFTER romaneio_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'carregamentos'
      AND column_name = 'remote_carregamento_id'
  ) THEN
    ALTER TABLE carregamentos ADD COLUMN remote_carregamento_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_comandos_clp'
      AND column_name = 'remote_command_id'
  ) THEN
    ALTER TABLE solicitacoes_comandos_clp ADD COLUMN remote_command_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND column_name = 'sync_token'
  ) THEN
    ALTER TABLE instalacoes_locais ADD COLUMN sync_token VARCHAR(64) NULL AFTER login_domain;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND column_name = 'last_remote_sync_at'
  ) THEN
    ALTER TABLE instalacoes_locais ADD COLUMN last_remote_sync_at DATETIME(3) NULL AFTER activated_at;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'instalacoes_locais'
      AND column_name = 'last_remote_sync_error'
  ) THEN
    ALTER TABLE instalacoes_locais ADD COLUMN last_remote_sync_error VARCHAR(1000) NULL AFTER last_remote_sync_at;
  END IF;
END//
DELIMITER ;
CALL trace_repair_sync_columns();
DROP PROCEDURE trace_repair_sync_columns;

DELIMITER //
CREATE PROCEDURE trace_repair_sync_indexes()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'equipamentos'
      AND index_name = 'uq_equipamento_empresa_remoto'
  ) THEN
    ALTER TABLE equipamentos ADD UNIQUE KEY uq_equipamento_empresa_remoto (company_id, remote_equipment_id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_empresa_remoto'
  ) THEN
    ALTER TABLE romaneios ADD UNIQUE KEY uq_romaneio_empresa_remoto (company_id, remote_romaneio_id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneio_caminhoes'
      AND index_name = 'uq_caminhao_romaneio_remoto'
  ) THEN
    ALTER TABLE romaneio_caminhoes ADD UNIQUE KEY uq_caminhao_romaneio_remoto (romaneio_id, remote_truck_id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'carregamentos'
      AND index_name = 'uq_carregamento_empresa_remoto'
  ) THEN
    ALTER TABLE carregamentos ADD UNIQUE KEY uq_carregamento_empresa_remoto (company_id, remote_carregamento_id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_comandos_clp'
      AND index_name = 'uq_comando_empresa_remoto'
  ) THEN
    ALTER TABLE solicitacoes_comandos_clp ADD UNIQUE KEY uq_comando_empresa_remoto (company_id, remote_command_id);
  END IF;
END//
DELIMITER ;
CALL trace_repair_sync_indexes();
DROP PROCEDURE trace_repair_sync_indexes;
