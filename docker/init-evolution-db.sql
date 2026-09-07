-- Só roda em bancos novos (initdb). Como o mysql-data já existe em produção,
-- em prod o database será criado à mão (ver wpp/README.md).
CREATE DATABASE IF NOT EXISTS evolution CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
