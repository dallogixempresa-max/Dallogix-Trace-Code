-- Mantém a contagem operacional no carregamento e explicita a empresa da leitura.
-- Isso reduz agregações repetidas no polling e reforça o isolamento por empresa.

ALTER TABLE carregamentos
  ADD COLUMN leituras_validas INT UNSIGNED NOT NULL DEFAULT 0 AFTER truck_id;

UPDATE carregamentos c
SET c.leituras_validas = (
  SELECT COUNT(*)
  FROM leituras l
  WHERE l.carregamento_id = c.id
    AND l.result = 'VALIDO'
);

ALTER TABLE leituras
  ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id;

UPDATE leituras l
JOIN carregamentos c ON c.id = l.carregamento_id
SET l.company_id = c.company_id
WHERE l.company_id IS NULL;

ALTER TABLE leituras
  MODIFY COLUMN company_id BIGINT UNSIGNED NOT NULL,
  ADD KEY idx_reading_company_loading_result (company_id, carregamento_id, result),
  ADD CONSTRAINT fk_reading_company FOREIGN KEY (company_id) REFERENCES empresas (id);
