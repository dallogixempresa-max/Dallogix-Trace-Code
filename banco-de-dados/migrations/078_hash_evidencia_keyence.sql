ALTER TABLE solicitacoes_imagens_evidencia
  ADD COLUMN image_sha256 CHAR(64) NULL AFTER captured_at;
