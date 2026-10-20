-- O código de uma empresa é permanente e não pode ser substituído por outro.
ALTER TABLE empresas
  ADD UNIQUE KEY uq_empresa_activation_code (activation_code);
