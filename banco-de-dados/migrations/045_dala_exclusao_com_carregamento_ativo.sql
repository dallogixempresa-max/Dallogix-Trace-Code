-- Permite manter o carregamento pendente quando a Dala vinculada é removida.
-- O romaneio, leituras e estado operacional continuam preservados; apenas a
-- máquina fica temporariamente sem vínculo até uma nova seleção.
SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_allow_detached_loading_equipment()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'carregamentos'
      AND column_name = 'equipment_id'
      AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE carregamentos
      MODIFY COLUMN equipment_id BIGINT UNSIGNED NULL;
  END IF;
END//
DELIMITER ;
CALL trace_allow_detached_loading_equipment();
DROP PROCEDURE trace_allow_detached_loading_equipment;
