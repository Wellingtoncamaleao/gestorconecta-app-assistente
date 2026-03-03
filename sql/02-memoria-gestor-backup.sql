-- ============================================
-- Memorias RAG — GestorBackup (negocios + estrategia)
-- Rodar no Supabase SQL Editor
-- ============================================

-- Produto
INSERT INTO assistente_memoria (categoria, chave, valor, relevancia) VALUES
('projeto', 'gestor-backup', 'Backup automatico para pequenas empresas. Agent Go (~10MB) + Backblaze B2 + PHP + Supabase. Pronto para producao. D:/GESTORCONECTA/APP/backup/', 2.0),
('projeto', 'gestor-backup-planos', 'Essencial R$100/100GB | Profissional R$200/500GB | Empresa R$300/1TB. Precos mantidos ate validar com 50 clientes.', 2.0),
('projeto', 'gestor-backup-margens', 'Custo B2: R$3,50 (100GB), R$17,40 (500GB), R$34,80 (1TB). Margens: 88-96%. Com comissao TI 25%: margem 63-71%.', 2.0)
ON CONFLICT (categoria, chave) DO UPDATE SET valor = EXCLUDED.valor, relevancia = EXCLUDED.relevancia;

-- Estrategia comercial
INSERT INTO assistente_memoria (categoria, chave, valor, relevancia) VALUES
('decisao', 'backup-canal-principal', 'Canal principal: TI local. 25% comissao recorrente. TI instala e acompanha, Wellington cuida da infra. Meta: 2 TIs novos/mes.', 2.5),
('decisao', 'backup-canal-contador', 'Canal secundario: contabilidades. 15% desconto unico na primeira mensalidade do indicado. Nunca pix, sempre desconto.', 2.0),
('decisao', 'backup-canal-indicacao', 'Canal paralelo: indicacao cliente-cliente. 15% desconto unico na mensalidade do indicador. Nunca pix, sempre desconto.', 2.0),
('decisao', 'backup-sem-erp', 'NAO vender para softwarehouses ERP/PDV — Wellington vai vender Control (ERP proprio), haveria conflito de canal.', 2.0),
('decisao', 'backup-quem-vende', 'Wellington NAO vende direto. TIs locais vendem. Modelo validado por Datasafer, SafeBKP, SEPTE no Brasil.', 2.5)
ON CONFLICT (categoria, chave) DO UPDATE SET valor = EXCLUDED.valor, relevancia = EXCLUDED.relevancia;

-- Projecoes
INSERT INTO assistente_memoria (categoria, chave, valor, relevancia) VALUES
('decisao', 'backup-projecao-realista', 'Mes 12 realista: 24 TIs, 312 clientes, R$42k receita, R$30k lucro. Acumulado ano 1: ~R$143k lucro.', 2.0),
('decisao', 'backup-projecao-cenarios', 'Pessimista: R$9k/mes (12 TIs, 100 clientes). Realista: R$30k/mes (24 TIs, 312). Otimista: R$55k/mes (36 TIs, 600).', 2.0),
('decisao', 'backup-ecossistema', 'Cross-sell via mesmo TI: Backup R$42k + Control R$15k + MED R$12k = R$69k/mes no mes 12. Custo aquisicao do 2o produto: quase zero.', 2.0)
ON CONFLICT (categoria, chave) DO UPDATE SET valor = EXCLUDED.valor, relevancia = EXCLUDED.relevancia;

-- Posicionamento
INSERT INTO assistente_memoria (categoria, chave, valor, relevancia) VALUES
('decisao', 'backup-posicionamento', 'Concorrente real: HD externo, Google Drive, nao fazer nada. NAO concorre com SaaS internacional. Diferencial: confianca local + WhatsApp + instalacao presencial.', 2.0),
('decisao', 'backup-mercado-referencia', 'Danysoft cobra R$999/1TB. Wellington cobra R$300/1TB (3.3x mais barato). CorpBackup R$19-89. SEPTE ~100 revendedores.', 1.5)
ON CONFLICT (categoria, chave) DO UPDATE SET valor = EXCLUDED.valor, relevancia = EXCLUDED.relevancia;
