-- Permite que um gatilho operacional do sensor registre comando sem atribuí-lo
-- falsamente a uma pessoa. Comandos iniciados pela interface continuam ligados
-- ao usuário autenticado; somente automações usam requested_by = NULL.
ALTER TABLE solicitacoes_comandos_clp
  MODIFY COLUMN requested_by BIGINT UNSIGNED NULL;
