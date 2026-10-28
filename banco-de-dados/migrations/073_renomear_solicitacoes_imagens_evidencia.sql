-- Renomeia a tabela legada de solicitações para imagens de evidência.
-- O conteúdo e as chaves existentes são preservados; agora ela guarda imagens
-- de erro enviadas pelo leitor Keyence.
RENAME TABLE solicitacoes_captura_camera TO solicitacoes_imagens_evidencia;

-- Desativa as credenciais antigas sem apagar o histórico de dispositivos.
UPDATE dispositivos SET active = 0 WHERE device_type = 'CAMERA';
