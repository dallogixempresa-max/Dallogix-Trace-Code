-- Liga divergências finais ao produto e ao carregamento que as originou.
-- A chave de referência torna o registro idempotente em reprocessamentos.
ALTER TABLE ocorrencias
ADD COLUMN product_id BIGINT UNSIGNED NULL AFTER carregamento_id,
ADD COLUMN reference_key VARCHAR(120) NULL AFTER product_id,
ADD KEY idx_occurrence_loading_type (carregamento_id, type),
ADD UNIQUE KEY uq_occurrence_reference (carregamento_id, type, reference_key),
ADD CONSTRAINT fk_occurrence_product FOREIGN KEY (product_id) REFERENCES products (id);
