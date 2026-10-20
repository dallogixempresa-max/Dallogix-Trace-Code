-- Consolida os quatro perfis oficiais do Dallogix Trace.
-- Usuários de manutenção passam para Supervisor por decisão de negócio.
UPDATE users
SET role = 'SUPERVISOR'
WHERE
  CAST(role AS CHAR) = 'MANUTENCAO';

UPDATE users
SET role = 'USUARIO'
WHERE
  CAST(role AS CHAR) = 'OPERADOR';

ALTER TABLE users
MODIFY COLUMN role ENUM(
  'ADMIN_DALLOGIX',
  'ADMIN_EMPRESA',
  'SUPERVISOR',
  'USUARIO'
) NOT NULL;
