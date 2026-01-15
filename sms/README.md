## SMS Manager (PHP 8)

Sistema MVC leve para envio de SMS em massa via Twilio.

### Requisitos
- PHP 8+
- MySQL
- Composer (para instalar `twilio/sdk`)

### Configuração rápida
1. Copie `.env.example` para `.env` e preencha dados do banco e Twilio:
   ```bash
   cp .env.example .env
   ```
2. Instale dependências (executa na raiz do projeto):
   ```bash
   composer require twilio/sdk
   ```
3. Crie o banco e tabelas:
   ```bash
   mysql -u seu_usuario -p -e "CREATE DATABASE sms_app CHARACTER SET utf8mb4;"
   mysql -u seu_usuario -p sms_app < database/schema.sql
   ```
4. Crie um hash de senha e atualize a linha de seed em `database/schema.sql` ou insira via SQL:
   ```php
   <?php echo password_hash('minha_senha', PASSWORD_DEFAULT);
   ```
5. Configure o servidor web apontando o DocumentRoot para `public/` (ex.: Apache com VirtualHost ou `php -S localhost:8000 -t public` para teste).

### Rotas principais
- `/?route=login` - login
- `/?route=dashboard` - resumo
- `/?route=contacts` - contatos
- `/?route=campaigns/new` - nova campanha
- `/?route=campaigns` - histórico

### Observação sobre o Twilio
Adicione `TWILIO_SID`, `TWILIO_TOKEN` e `TWILIO_FROM` no `.env`. O serviço `App\Services\TwilioSmsService` usa essas variáveis para enviar SMS e retorna status para gravar em `campaign_recipients`.
