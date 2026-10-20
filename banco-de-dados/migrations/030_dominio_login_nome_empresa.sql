-- Corrige os domínios das empresas antigas para usar o nome cadastrado.
-- Exemplo: Empresa Chat -> dallogix.empresa-chat.

SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE trace_migrate_company_login_domains()
BEGIN
  DECLARE finished BOOLEAN DEFAULT FALSE;
  DECLARE v_company_id INT;
  DECLARE v_company_name VARCHAR(160);
  DECLARE base_domain VARCHAR(120);
  DECLARE candidate_domain VARCHAR(120);
  DECLARE suffix_number INT DEFAULT 2;
  DECLARE suffix_text VARCHAR(20);

  DECLARE companies_cursor CURSOR FOR
    SELECT id, name FROM empresas ORDER BY id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;

  OPEN companies_cursor;
  company_loop: LOOP
    FETCH companies_cursor INTO v_company_id, v_company_name;
    IF finished THEN
      LEAVE company_loop;
    END IF;

    SET base_domain = LOWER(TRIM(v_company_name));
    SET base_domain = REPLACE(base_domain, 'á', 'a');
    SET base_domain = REPLACE(base_domain, 'à', 'a');
    SET base_domain = REPLACE(base_domain, 'â', 'a');
    SET base_domain = REPLACE(base_domain, 'ã', 'a');
    SET base_domain = REPLACE(base_domain, 'ä', 'a');
    SET base_domain = REPLACE(base_domain, 'é', 'e');
    SET base_domain = REPLACE(base_domain, 'è', 'e');
    SET base_domain = REPLACE(base_domain, 'ê', 'e');
    SET base_domain = REPLACE(base_domain, 'ë', 'e');
    SET base_domain = REPLACE(base_domain, 'í', 'i');
    SET base_domain = REPLACE(base_domain, 'ì', 'i');
    SET base_domain = REPLACE(base_domain, 'î', 'i');
    SET base_domain = REPLACE(base_domain, 'ï', 'i');
    SET base_domain = REPLACE(base_domain, 'ó', 'o');
    SET base_domain = REPLACE(base_domain, 'ò', 'o');
    SET base_domain = REPLACE(base_domain, 'ô', 'o');
    SET base_domain = REPLACE(base_domain, 'õ', 'o');
    SET base_domain = REPLACE(base_domain, 'ö', 'o');
    SET base_domain = REPLACE(base_domain, 'ú', 'u');
    SET base_domain = REPLACE(base_domain, 'ù', 'u');
    SET base_domain = REPLACE(base_domain, 'û', 'u');
    SET base_domain = REPLACE(base_domain, 'ü', 'u');
    SET base_domain = REPLACE(base_domain, 'ç', 'c');
    SET base_domain = REPLACE(base_domain, 'ñ', 'n');
    SET base_domain = REGEXP_REPLACE(base_domain, '[^a-z0-9]+', '-');
    SET base_domain = TRIM(BOTH '-' FROM base_domain);
    IF base_domain = '' THEN
      SET base_domain = 'empresa';
    END IF;
    SET base_domain = CONCAT('dallogix.', LEFT(base_domain, 111));
    SET candidate_domain = base_domain;
    SET suffix_number = 2;

    WHILE EXISTS (
      SELECT 1
      FROM empresas other_company
      WHERE other_company.login_domain = candidate_domain
        AND other_company.id <> v_company_id
    ) OR EXISTS (
      SELECT 1
      FROM usuarios other_user
      WHERE (other_user.company_id IS NULL OR other_user.company_id <> v_company_id)
        AND other_user.email LIKE CONCAT('%@', candidate_domain)
    ) DO
      SET suffix_text = CONCAT('-', suffix_number);
      SET candidate_domain = CONCAT(
        LEFT(base_domain, 120 - CHAR_LENGTH(suffix_text)),
        suffix_text
      );
      SET suffix_number = suffix_number + 1;
    END WHILE;

    UPDATE empresas
    SET login_domain = candidate_domain
    WHERE id = v_company_id;

    UPDATE usuarios
    SET email = CONCAT(SUBSTRING_INDEX(email, '@', 1), '@', candidate_domain)
    WHERE usuarios.company_id = v_company_id;
  END LOOP;
  CLOSE companies_cursor;
END//
DELIMITER ;

CALL trace_migrate_company_login_domains();
DROP PROCEDURE trace_migrate_company_login_domains;
