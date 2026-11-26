-- A API e a tela aceitam códigos de até 128 caracteres (SolicitacaoLeitura,
-- captura-leitor-hid.js). A coluna original tinha 80 e cortava ou recusava
-- leituras mais longas.
ALTER TABLE leituras MODIFY barcode VARCHAR(128) NULL;
