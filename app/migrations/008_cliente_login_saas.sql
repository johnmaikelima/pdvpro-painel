-- Migration 008: Login SaaS do cliente
ALTER TABLE clientes ADD COLUMN login_saas VARCHAR(50) DEFAULT NULL AFTER api_token;
