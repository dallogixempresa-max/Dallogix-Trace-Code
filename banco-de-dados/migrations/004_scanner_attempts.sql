ALTER TABLE leituras
ADD COLUMN attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER barcode,
ADD KEY idx_reading_loading_attempt (carregamento_id, attempt_number);
