-- O código fica disponível somente no gerenciamento Master para consulta e cópia.
ALTER TABLE empresas
  ADD COLUMN activation_code CHAR(13) NULL AFTER login_domain;
