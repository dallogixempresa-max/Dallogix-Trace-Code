-- O catálogo central é a fonte de verdade do PC industrial.
-- O identificador remoto permite atualizar SKU, nome e categoria sem criar
-- duplicidades quando o código do produto mudar no servidor.

SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_catalogo_produtos_remoto()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'produtos'
      AND column_name = 'remote_product_id'
  ) THEN
    ALTER TABLE produtos ADD COLUMN remote_product_id BIGINT UNSIGNED NULL AFTER company_id;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'produtos'
      AND index_name = 'uq_produto_empresa_remoto'
  ) THEN
    ALTER TABLE produtos ADD UNIQUE KEY uq_produto_empresa_remoto (company_id, remote_product_id);
  END IF;
END//
DELIMITER ;

CALL trace_catalogo_produtos_remoto();
DROP PROCEDURE trace_catalogo_produtos_remoto;
