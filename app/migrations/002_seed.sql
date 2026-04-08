-- ============================================
--   PDV Pro - Painel Admin - Dados Iniciais
-- ============================================

-- Admin padrao (senha: admin123)
INSERT INTO admins (nome, email, senha) VALUES
('Administrador', 'admin@pdvpro.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Planos Desktop
INSERT INTO planos (nome, slug, tipo_produto, periodo, preco, limite_nfce, limite_terminais, recursos) VALUES
('Free', 'desktop-free', 'desktop', 'mensal', 0.00, 50, 1, '{"nfce": true, "relatorios": true, "suporte": false}'),
('Basic Mensal', 'desktop-basic-mensal', 'desktop', 'mensal', 49.90, 500, 1, '{"nfce": true, "relatorios": true, "suporte": "email"}'),
('Basic Trimestral', 'desktop-basic-trimestral', 'desktop', 'trimestral', 129.90, 500, 1, '{"nfce": true, "relatorios": true, "suporte": "email"}'),
('Basic Anual', 'desktop-basic-anual', 'desktop', 'anual', 479.90, 500, 1, '{"nfce": true, "relatorios": true, "suporte": "email"}'),
('Pro Mensal', 'desktop-pro-mensal', 'desktop', 'mensal', 99.90, 0, 1, '{"nfce": true, "relatorios": true, "suporte": "prioritario"}'),
('Pro Trimestral', 'desktop-pro-trimestral', 'desktop', 'trimestral', 269.90, 0, 1, '{"nfce": true, "relatorios": true, "suporte": "prioritario"}'),
('Pro Anual', 'desktop-pro-anual', 'desktop', 'anual', 959.90, 0, 1, '{"nfce": true, "relatorios": true, "suporte": "prioritario"}');

-- Planos SaaS
INSERT INTO planos (nome, slug, tipo_produto, periodo, preco, limite_nfce, limite_terminais, recursos) VALUES
('Starter Mensal', 'saas-starter-mensal', 'saas', 'mensal', 99.90, 1000, 1, '{"nfce": true, "relatorios": true, "suporte": "email", "backup": true}'),
('Business Mensal', 'saas-business-mensal', 'saas', 'mensal', 199.90, 0, 3, '{"nfce": true, "relatorios": true, "suporte": "prioritario", "backup": true, "multi_terminal": true}'),
('Enterprise Mensal', 'saas-enterprise-mensal', 'saas', 'mensal', 399.90, 0, 0, '{"nfce": true, "relatorios": true, "suporte": "dedicado", "backup": true, "multi_terminal": true, "api": true}');
