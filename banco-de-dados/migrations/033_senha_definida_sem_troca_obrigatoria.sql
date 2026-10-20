-- A senha informada na criação ou redefinição do login já é definitiva.
UPDATE usuarios
SET must_change_password = 0;
