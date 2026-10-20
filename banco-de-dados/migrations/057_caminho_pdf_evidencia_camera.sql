DELIMITER //
CREATE PROCEDURE renomear_caminho_pdf_evidencia_camera()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'solicitacoes_captura_camera'
      AND column_name = 'image_path'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'solicitacoes_captura_camera'
      AND column_name = 'evidence_pdf_path'
  ) THEN
    ALTER TABLE solicitacoes_captura_camera
      CHANGE COLUMN image_path evidence_pdf_path VARCHAR(500) NULL;
  END IF;
END//
CALL renomear_caminho_pdf_evidencia_camera()//
DROP PROCEDURE renomear_caminho_pdf_evidencia_camera//
DELIMITER ;
