-- Compatibilidade para instalações que já tinham os domínios provisórios
-- dallogix.empresa-1 e dallogix.empresa-2 registrados.

SET NAMES utf8mb4;

UPDATE empresas
SET login_domain = 'dallogix.empresa-chat'
WHERE name = 'Empresa Chat'
  AND login_domain = 'dallogix.empresa-2';

UPDATE usuarios u
JOIN empresas e ON e.id = u.company_id
SET u.email = CONCAT(SUBSTRING_INDEX(u.email, '@', 1), '@dallogix.empresa-chat')
WHERE e.name = 'Empresa Chat'
  AND u.email LIKE '%@dallogix.empresa-2';

UPDATE empresas
SET login_domain = 'dallogix.empresa-demonstracao'
WHERE name = CONVERT(0x456D70726573612044656D6F6E73747261C3A7C3A36F USING utf8mb4)
  AND login_domain = 'dallogix.empresa-1';

UPDATE usuarios u
JOIN empresas e ON e.id = u.company_id
SET u.email = CONCAT(SUBSTRING_INDEX(u.email, '@', 1), '@dallogix.empresa-demonstracao')
WHERE e.name = CONVERT(0x456D70726573612044656D6F6E73747261C3A7C3A36F USING utf8mb4)
  AND u.email LIKE '%@dallogix.empresa-1';
