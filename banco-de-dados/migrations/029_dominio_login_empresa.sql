-- Cada empresa passa a ter um domínio lógico próprio para os acessos.
-- Exemplo: admin@dallogix.empresa-chat.

DELIMITER //
CREATE PROCEDURE trace_ensure_migration_029_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'empresas'
      AND column_name = 'login_domain'
  ) THEN
    ALTER TABLE empresas ADD COLUMN login_domain VARCHAR(120) NULL AFTER name;
  END IF;
END//
DELIMITER ;
CALL trace_ensure_migration_029_columns();
DROP PROCEDURE trace_ensure_migration_029_columns;

-- Empresas antigas recebem um identificador estável. Novas empresas usam o
-- identificador legível gerado pela API a partir do nome cadastrado.
UPDATE empresas
SET login_domain = CONCAT('dallogix.empresa-', id)
WHERE login_domain IS NULL OR login_domain = '';

ALTER TABLE empresas
  MODIFY COLUMN login_domain VARCHAR(120) NOT NULL;

DELIMITER //
CREATE PROCEDURE trace_ensure_migration_029_index()
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'empresas'
      AND index_name = 'uq_empresa_login_domain'
  ) THEN
    ALTER TABLE empresas ADD UNIQUE KEY uq_empresa_login_domain (login_domain);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_migration_029_index();
DROP PROCEDURE trace_ensure_migration_029_index;
