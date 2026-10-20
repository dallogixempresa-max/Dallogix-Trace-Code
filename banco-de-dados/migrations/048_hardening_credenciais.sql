-- Endurece as credenciais de instalação e permite autenticação de dispositivo
-- com seleção indexada, sem percorrer hashes bcrypt em tentativas inválidas.

SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_ensure_credential_hardening()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'empresas'
      AND column_name = 'installation_token_hash'
  ) THEN
    ALTER TABLE empresas ADD COLUMN installation_token_hash CHAR(64) NULL AFTER activation_code_hash;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'empresas'
      AND index_name = 'uq_empresa_installation_token_hash'
  ) THEN
    ALTER TABLE empresas ADD UNIQUE KEY uq_empresa_installation_token_hash (installation_token_hash);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND column_name = 'token_lookup_hash'
  ) THEN
    ALTER TABLE dispositivos ADD COLUMN token_lookup_hash CHAR(64) NULL AFTER token_hash;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND index_name = 'uq_dispositivo_token_lookup_hash'
  ) THEN
    ALTER TABLE dispositivos ADD UNIQUE KEY uq_dispositivo_token_lookup_hash (token_lookup_hash);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_credential_hardening();
DROP PROCEDURE trace_ensure_credential_hardening;

-- Registros antigos não têm seletor seguro. Desative-os até que o operador
-- reprovisione cada dispositivo com scripts/provision_device.php.
UPDATE dispositivos SET active = 0 WHERE token_lookup_hash IS NULL;
