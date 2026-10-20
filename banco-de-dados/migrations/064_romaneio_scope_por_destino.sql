-- Preserva a unicidade por origem em romaneios sincronizados e permite que
-- integrações reutilizem o número em Dalas diferentes no servidor Central.
SET NAMES utf8mb4;

DELIMITER //
DROP PROCEDURE IF EXISTS trace_align_romaneio_assignment_scope//
CREATE PROCEDURE trace_align_romaneio_assignment_scope()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND column_name = 'assigned_installation_id'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration 063 deve ser aplicada antes da migration 064.';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_company_installation_number'
  ) THEN
    ALTER TABLE romaneios DROP INDEX uq_romaneio_company_installation_number;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_company_installation_number'
  ) THEN
    ALTER TABLE romaneios
      ADD UNIQUE INDEX uq_romaneio_company_installation_number
        (company_id, (COALESCE(assigned_installation_id, remote_installation_id, 0)), number);
  END IF;
END//
DELIMITER ;

CALL trace_align_romaneio_assignment_scope();
DROP PROCEDURE trace_align_romaneio_assignment_scope;
