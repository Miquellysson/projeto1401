# Get Power Research (MySQL) — MVP

**Stack**: Apache + PHP 7.4/8.x + MySQL.  
**Features**: multilíngue (PT/EN/ES), catálogo, carrinho, checkout com **Zelle (upload comprovante)**, **Venmo**, **Pix** (payload + QR via serviço público), **PayPal**, painel admin (produtos/clientes/pedidos).

## 1) Instalação
1. Crie o banco `get_power` no MySQL (ou outro nome).
2. Suba esta pasta para seu Apache (ex.: `/var/www/html/get-power`).
3. Ajuste `config.php` com seu host/DB/user/pass (ou use variáveis de ambiente `FF_DB_HOST`, `FF_DB_NAME`, `FF_DB_USER`, `FF_DB_PASS`).
4. Acesse `http://SEU_HOST/get-power/install.php` uma vez para criar as tabelas e dados demo.
5. Loja: `index.php` — Painel: `admin.php` (defina suas credenciais via variáveis `FF_ADMIN_EMAIL` e `FF_ADMIN_PASS_HASH` ou cadastre manualmente).

## 2) Pagamentos
- **Pix**: payload gerado localmente; QR renderizado via `https://api.qrserver.com`. Para QR local, substitua por uma biblioteca PHP QRCode.
- **Zelle**: sem API pública → fluxo com **upload de comprovante** anexado ao pedido.
- **Venmo**: link para perfil/handle.
- **PayPal**: link de checkout simples; para produção, implemente **webhooks** de confirmação.

## 3) Segurança & produção
- Altere credenciais admin e mova `storage/` fora da raiz pública, se possível.
- Limite tipos de upload, tamanho e ative HTTPS.
- Configure permissões de escrita para `storage/zelle_receipts`.
- Adicione cabeçalhos de segurança no Apache (HSTS, CSP, X-Frame-Options).

### Recuperação de senha
- Execute a migração da tabela `password_resets` descrita em `MIGRATIONS.md`.
- Configure a variável `FF_APP_BASE_URL` (ou `app_base_url` em `config.php`) apontando para a URL pública do projeto — usada para gerar os links do e-mail.
- Verifique as credenciais SMTP/serviço de e-mail no servidor (a função `send_password_reset_email` usa `mail()` por padrão, adapte se necessário).
- Garanta que o cron/monitoramento acompanhe tentativas excessivas (rate limit de 3 envios/hora por IP/e-mail).

## 4) Roadmap
- Webhooks PayPal, reconciliação Venmo/Zelle.
- Logs estruturados e paginação.
- Integração de emails transacionais.
- Migração de QR local via biblioteca (phpqrcode) ao invés de serviço público.

## Guia de Instalação (Claude.ai)
(Conteúdo resumido e aplicado: encoding UTF-8, categorias, notificações em tempo real, UX moderna com Tailwind, compatibilidade Apache/MySQL, segurança CSRF, validações, carrinho AJAX, checkout moderno, multilíngue PT/EN/ES, PIX QR, backup, logs, cache, otimização de imagens.)

Consulte também os arquivos .htaccess na raiz e em /storage conforme orientação.
