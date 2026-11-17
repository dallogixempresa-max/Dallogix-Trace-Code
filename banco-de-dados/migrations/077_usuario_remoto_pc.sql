-- Vincula uma conta local autenticada no Central à sua identidade de origem.
-- O campo fica vazio para contas criadas somente nesta instalação.
ALTER TABLE usuarios
  ADD COLUMN remote_user_id BIGINT UNSIGNED NULL AFTER company_id,
  ADD UNIQUE KEY uq_usuarios_remote_user_id (remote_user_id);
