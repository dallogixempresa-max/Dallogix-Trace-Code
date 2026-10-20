-- Metadados para revogar e rotacionar a credencial de um dispositivo sem
-- alterar os tokens de outras Dalas.
DELIMITER //
CREATE PROCEDURE trace_ensure_device_token_rotation()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'token_id') THEN
    ALTER TABLE dispositivos ADD COLUMN token_id CHAR(36) NULL AFTER token_lookup_hash;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'token_created_at') THEN
    ALTER TABLE dispositivos ADD COLUMN token_created_at DATETIME NULL AFTER token_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'token_last_used_at') THEN
    ALTER TABLE dispositivos ADD COLUMN token_last_used_at DATETIME NULL AFTER token_created_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'token_expires_at') THEN
    ALTER TABLE dispositivos ADD COLUMN token_expires_at DATETIME NULL AFTER token_last_used_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'token_revoked_at') THEN
    ALTER TABLE dispositivos ADD COLUMN token_revoked_at DATETIME NULL AFTER token_expires_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND index_name = 'uq_dispositivo_token_id') THEN
    ALTER TABLE dispositivos ADD UNIQUE KEY uq_dispositivo_token_id (token_id);
  END IF;
END//
DELIMITER ;
CALL trace_ensure_device_token_rotation();
DROP PROCEDURE trace_ensure_device_token_rotation;

UPDATE dispositivos
SET token_id = UUID(), token_created_at = COALESCE(token_created_at, created_at)
WHERE token_id IS NULL;
