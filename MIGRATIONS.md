
# Migrações aplicadas (2025-09-18)
- Adicionada categoria padrão: **Anticoncepcionais** (seed em `install.php`).
- Adicionado campo `orders.track_token` (create/migrate em `install.php`) para link público de acompanhamento de pedido.
- Frete fixo definido: **$ 7.00** (checkout e total).
- Moeda padrão alterada para **USD** (símbolo `$`) — `config.php` e textos hardcoded atualizados.
- `install.php` agora cria também as tabelas `order_items` (compatível com `diag.php`) e `settings` (preferências chave/valor usadas pelo painel).
- Campo `products.square_payment_link` criado para suportar links diretos do Square por produto.
- Campo `products.stripe_payment_link` criado para permitir checkout direto via Stripe por produto.
- Campo `products.paypal_payment_link` criado para permitir checkout direto via PayPal por produto (modo configurável na aba de pagamentos).
- Campo `products.price_compare` adicionado para habilitar exibição de ofertas no formato **de X por Y**.
- Novas chaves em `settings`: `home_featured_enabled`, `home_featured_title`, `home_featured_subtitle`, `home_featured_label`, `home_featured_badge_title` e `home_featured_badge_text` para controlar a vitrine de destaques na home.
- Campo `footer_copy` permite personalizar o texto de rodapé com placeholders (`{{year}}`, `{{store_name}}`).
- Novas opções do método Square (rótulos/links para crédito, débito e Afterpay) e blocos de texto gerenciáveis.
- Templates de e-mail (cliente/admin) passaram a ser configuráveis via painel (`email_customer_*` e `email_admin_*`).
- Criada tabela `page_layouts` para armazenar rascunhos/publicações do editor visual da home.
- Criada tabela `payment_methods` para gerenciar métodos de pagamento dinâmicos via painel.

## 2025-11-05 — Checkout e notificações
- Checkout solicita nome, sobrenome, país, endereço completo (linha 2 opcional) e método de entrega – novas colunas `customers.first_name`, `customers.last_name`, `customers.address2` e default de país `US`.
- Pedidos guardam o método de entrega escolhido (`orders.delivery_method_code`, `orders.delivery_method_label`, `orders.delivery_method_details`).
- Configurações administráveis para países, estados e métodos de entrega (`checkout_countries`, `checkout_states`, `checkout_delivery_methods`, `checkout_default_country`).
- Templates de e-mail atualizados com layout responsivo estilo cartão, incluindo novas variáveis (`billing_*`, `shipping_*`, `order_items_rows`, `shipping_method` etc.).
- Aba “Dados da loja” agora aceita o snippet completo do Google Analytics (`google_analytics_code`), injetado automaticamente no `<head>` da vitrine.
- Conteúdos das páginas “Política de Privacidade” e “Política de Reembolso” gerenciados via painel (`privacy_policy_content`, `refund_policy_content`), com rotas públicas dedicadas.
- Área exclusiva para super administradores gerar, restaurar e gerenciar backups completos (dados + arquivos) em `backup.php`, com pacotes armazenados em `storage/backups`.
- Produtos agora possuem `slug` único para URLs amigáveis e tabela auxiliar `product_details` (descrições, especificações, galeria, vídeo) alimentando a nova PDP.

## 2025-11-08 — Afiliados
- Criada a tabela `affiliates` para gerenciar links personalizados.
- Pedidos agora podem guardar `orders.affiliate_id` e `orders.affiliate_code` para exibir o afiliado no painel.

## 2025-11-12 — Pagamentos e links por produto
- Adicionados `payment_methods.is_featured` (preferencial) e `payment_methods.public_note` (observacao no checkout).
- Criada a tabela `product_payment_links` para armazenar links por produto de novos metodos de pagamento.

## Como aplicar no ambiente existente
1. Faça backup do banco.
2. Suba os arquivos alterados.
3. Rode `/install.php` no navegador (idempotente). Ele criará o campo `track_token` se não existir e fará o seed da categoria **Anticoncepcionais** (não duplica).
   - Esse passo também garante a criação das tabelas `order_items` e `settings` quando ausentes.
   - Inclui os campos `square_payment_link`, `stripe_payment_link` e `price_compare` na tabela `products` sem impactar cadastros existentes.
   - Cria/atualiza a tabela `page_layouts` com a coluna `meta` (se ausente).
   - Provisiona a tabela `payment_methods` e popula com Pix/Zelle/Venmo/PayPal/Square caso esteja vazia.
- Semeia as preferências da vitrine de destaques (`home_featured_*`) com valores padrão (incluindo selo, título e descrição) e mantém desativada até habilitar no painel.
- Popula os templates de e-mail (`email_customer_*` e `email_admin_*`) com mensagens padrão personalizáveis.
- Adiciona o texto padrão de rodapé (`footer_copy`) usando placeholders dinâmicos.

## Link de acompanhamento
- Depois do pedido, o cliente verá o link.
- Por e-mail, o link é enviado (usa `cfg()['store']['base_url']` se definido; senão, link relativo `/index.php?route=track&code=...`).

## 2025-02-14 — Recuperação de senha
- Nova tabela `password_resets` para controlar tokens individuais:
  ```sql
  CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_request VARCHAR(45),
    user_agent VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    INDEX idx_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  );
  ```
- Endpoints `api/forgot_password_request.php` e `api/reset_password_submit.php` criados.
- Novas páginas `forgot_password.php` e `reset_password.php` para fluxo completo com validações e mensagens amigáveis.
- Templates de e-mail HTML e texto (`emails/password_reset.*`).
