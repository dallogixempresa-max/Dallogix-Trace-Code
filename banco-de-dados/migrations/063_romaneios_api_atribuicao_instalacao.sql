-- Romaneios criados por integrações podem ser direcionados a um PC industrial.
-- NULL preserva os registros locais/legados sem atribuição central.
DELIMITER //
CREATE PROCEDURE trace_ensure_romaneio_installation_assignment()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND column_name = 'assigned_installation_id'
  ) THEN
    ALTER TABLE romaneios
      ADD COLUMN assigned_installation_id BIGINT UNSIGNED NULL AFTER remote_installation_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'uq_romaneio_company_assigned_number'
  ) THEN
    ALTER TABLE romaneios
      ADD UNIQUE KEY uq_romaneio_company_assigned_number
        (company_id, assigned_installation_id, number);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'romaneios'
      AND index_name = 'idx_romaneio_assigned_status'
  ) THEN
    ALTER TABLE romaneios
      ADD KEY idx_romaneio_assigned_status
        (company_id, assigned_installation_id, status, scheduled_date, id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'romaneios'
      AND constraint_name = 'fk_romaneio_assigned_installation'
  ) THEN
    ALTER TABLE romaneios
      ADD CONSTRAINT fk_romaneio_assigned_installation
      FOREIGN KEY (assigned_installation_id) REFERENCES instalacoes_industriais (id)
      ON DELETE SET NULL;
  END IF;
END//
DELIMITER ;
CALL trace_ensure_romaneio_installation_assignment();
DROP PROCEDURE trace_ensure_romaneio_installation_assignment;
