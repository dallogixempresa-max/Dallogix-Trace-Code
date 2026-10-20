-- Etapa 24: alinhamento ao TracePlatform (referência)
-- Expedidor no nível do romaneio, categoria de produto e preferência de busca da importação PDF.
ALTER TABLE romaneios
ADD COLUMN expedidor VARCHAR(160) NULL AFTER number;

ALTER TABLE products
ADD COLUMN category VARCHAR(120) NULL AFTER name;

ALTER TABLE company_settings
ADD COLUMN pdf_search_field ENUM('barcode', 'sku') NOT NULL DEFAULT 'barcode' AFTER pdf_field_mapping;
