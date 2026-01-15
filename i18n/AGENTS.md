# Repository Guidelines

## Project Structure & Module Organization
- `index.php` runs the storefront, while `admin.php` and `admin_app.html` host the admin UI; shared helpers sit under `lib/`.
- Assets live in `assets/` plus `theme.css`/`theme.js`; translations in `i18n/`; installers e docs (`install.php`, `MIGRATIONS.md`) ficam na raiz junto com diagnósticos rápidos.
- Cadastro de produtos (`products.php`) persiste o campo `square_payment_link`, usado no checkout para redirecionar ao Square.
- O painel de configurações unificado (`settings.php`) traz abas para dados gerais, métodos de pagamento e editor visual; ainda usa `admin_api_layouts.php` para salvar layouts na tabela `page_layouts` e `payment_methods` para armazenar formas de pagamento.
- Runtime uploads persist in `storage/` (`zelle_receipts/`, `products/`, `logo/`). Keep writable paths aligned with `config.php` and shielded in production.

## Build, Test, and Development Commands
- `php -S localhost:8080 -t .` — start a local PHP server to exercise storefront and admin flows.
- `php lint_all.php` — syntax-check the entire PHP surface before pushing.
- `php test.php` and `php selftest.php` — run sanity and environment diagnostics; extend these scripts when adding new integrations.
- Configurações: tudo está em `settings.php` — use as abas "Pagamentos" (drag-and-drop, upload de ícone) e "Editor da Home" (GrapesJS com salvar/preview/publicar via `admin_api_layouts.php`).

## Coding Style & Naming Conventions
- Follow PSR-12 habits: 4 spaces, braces on the next line, upper snake-case constants (`DB_HOST`), descriptive snake_case helpers.
- Place reusable logic in `lib/` and keep page controllers lean, delegating side-effects to helpers.
- Name assets and locale files in lowercase with hyphens or country codes (`theme.css`, `pt.php`); stick to this pattern for new resources.

## Testing Guidelines
- Create focused scripts named `test-<feature>.php`; they should fail fast when configuration prerequisites are missing.
- Re-test checkout, payment uploads, and language switching after touching `admin*.php` or `lib/utils.php`; confirm receipts land in `storage/zelle_receipts/`.
- Capture manual steps or automated coverage expectations in PR descriptions, prioritising checkout, payment gateways, and session handling.

## Commit & Pull Request Guidelines
- Use imperative, scoped commit titles; prefer Conventional Commits prefixes (`feat:`, `fix:`, `chore:`) for clarity.
- PRs should list executed commands, flag schema/config updates, and provide before/after screenshots for UI tweaks.
- Strip secrets from `config.php` before review and rely on environment variables (`FF_DB_*`) or deployment overrides.

## Security & Configuration Tips
- Rotate admin credentials and payment handles on first deploy; enforce HTTPS and restrict upload MIME types.
- Consider moving `storage/` outside the web root and enabling Apache security headers (HSTS, CSP, X-Frame-Options).
- Replace the public QR service with a local generator prior to launch and persist payment audit logs under `storage/logs/`.
