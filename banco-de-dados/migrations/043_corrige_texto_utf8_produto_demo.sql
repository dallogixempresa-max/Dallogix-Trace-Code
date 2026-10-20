SET NAMES utf8mb4;

-- Corrige o produto criado pelo seed antigo quando a string UTF-8 foi
-- interpretada como latin1. A condição torna a migration idempotente.
UPDATE produtos
SET name = 'Ração cães adulto • 10,1 KG'
WHERE code = 'PROD3'
  AND name LIKE 'RaÃ§Ã£o cÃ£es adulto%';
