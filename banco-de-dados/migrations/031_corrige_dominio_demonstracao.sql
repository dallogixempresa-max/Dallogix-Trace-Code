-- Corrige o domínio da empresa de demonstração caso a migration 030 tenha
-- sido executada em uma conexão MySQL que não estava em utf8mb4.

SET NAMES utf8mb4;

UPDATE empresas
SET login_domain = 'dallogix.empresa-demonstracao'
WHERE login_domain = 'dallogix.empresa-demonstra-o';

UPDATE usuarios u
JOIN empresas e ON e.id = u.company_id
SET u.email = CONCAT(SUBSTRING_INDEX(u.email, '@', 1), '@dallogix.empresa-demonstracao')
WHERE e.login_domain = 'dallogix.empresa-demonstracao'
  AND u.email LIKE '%@dallogix.empresa-demonstra-o';
