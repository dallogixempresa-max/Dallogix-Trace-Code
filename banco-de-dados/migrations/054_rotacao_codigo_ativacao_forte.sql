-- Atualiza o formato para 130 bits aleatórios e invalida os códigos curtos já
-- emitidos. Novos códigos expiram em 7 dias e só podem ser usados uma vez.
ALTER TABLE empresas
  MODIFY COLUMN activation_code VARCHAR(40) NULL,
  ADD COLUMN activation_code_expires_at DATETIME NULL AFTER activation_code_created_at,
  ADD COLUMN activation_code_used_at DATETIME NULL AFTER activation_code_expires_at;

UPDATE empresas
SET activation_code = NULL,
    activation_code_hash = NULL,
    activation_code_preview = NULL,
    activation_code_created_at = NULL,
    activation_code_expires_at = NULL,
    activation_code_used_at = NULL
WHERE activation_code IS NOT NULL OR activation_code_hash IS NOT NULL;
