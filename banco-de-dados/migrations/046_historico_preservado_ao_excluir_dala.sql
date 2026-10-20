-- O histórico operacional pode continuar existindo sem a Dala física.
-- Ao excluir a Dala, estas referências ficam sem máquina, mas os eventos,
-- leituras e solicitações continuam preservados para auditoria.
SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_allow_history_without_equipment()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'eventos_sensor'
      AND column_name = 'equipment_id' AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE eventos_sensor MODIFY COLUMN equipment_id BIGINT UNSIGNED NULL;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_comandos_clp'
      AND column_name = 'equipment_id' AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE solicitacoes_comandos_clp MODIFY COLUMN equipment_id BIGINT UNSIGNED NULL;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'solicitacoes_captura_camera'
      AND column_name = 'equipment_id' AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE solicitacoes_captura_camera MODIFY COLUMN equipment_id BIGINT UNSIGNED NULL;
  END IF;
END//
DELIMITER ;
CALL trace_allow_history_without_equipment();
DROP PROCEDURE trace_allow_history_without_equipment;
