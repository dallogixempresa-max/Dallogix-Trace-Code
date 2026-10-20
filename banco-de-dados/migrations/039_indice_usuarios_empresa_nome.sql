-- A tela de usuários filtra por empresa e ordena por nome.
-- O índice composto evita filesort nessa consulta.
ALTER TABLE usuarios
  ADD KEY idx_usuarios_company_name (company_id, name);
