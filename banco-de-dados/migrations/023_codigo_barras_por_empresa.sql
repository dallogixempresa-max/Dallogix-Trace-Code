-- Códigos de barras são identificadores do catálogo de cada empresa.
-- O mesmo produto/código pode existir em empresas diferentes.
ALTER TABLE codigos_produtos ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id;
UPDATE codigos_produtos pc JOIN produtos p ON p.id = pc.product_id SET pc.company_id = p.company_id WHERE pc.company_id IS NULL;
ALTER TABLE codigos_produtos DROP INDEX uq_product_barcode;
ALTER TABLE codigos_produtos MODIFY company_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE codigos_produtos ADD UNIQUE KEY uq_company_product_barcode (company_id, barcode);
ALTER TABLE codigos_produtos ADD KEY idx_codes_company_product (company_id, product_id);
ALTER TABLE codigos_produtos ADD CONSTRAINT fk_codes_company FOREIGN KEY (company_id) REFERENCES empresas (id);
