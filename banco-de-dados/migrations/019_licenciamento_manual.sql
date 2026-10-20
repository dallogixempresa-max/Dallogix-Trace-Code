-- Licenciamento passa a ser controlado somente por bloqueio/desbloqueio manual.
UPDATE licenses
SET status = 'BLOQUEADA'
WHERE status IN ('INADIMPLENTE', 'CANCELADA');

ALTER TABLE licenses
  DROP FOREIGN KEY fk_license_company;

ALTER TABLE licenses
  DROP INDEX idx_license_company_status,
  DROP INDEX idx_license_due_at,
  DROP COLUMN due_at,
  DROP COLUMN grace_until,
  MODIFY COLUMN status ENUM('ATIVA', 'BLOQUEADA') NOT NULL DEFAULT 'ATIVA',
  ADD INDEX idx_license_company_status (company_id, status);

ALTER TABLE licenses
  ADD CONSTRAINT fk_license_company FOREIGN KEY (company_id) REFERENCES companies (id);
