Get Power Research — Pacote de Tema + A2HS (Add to Home Screen)

Arquivos incluídos:
- theme.css          → tokens de design (light/dark) sem quebrar seu CSS atual
- theme.js           → alternador de tema persistente (localStorage)
- a2hs.js            → botão "Adicionar ao celular" (Android/iOS)
- manifest.webmanifest
- sw.js              → service worker mínimo (opcional)
- dashboard.php      → cards + botões (tema, visitar loja, A2HS)
- orders.php, products.php, categories.php, customers.php, users.php, settings.php → placeholders visuais
- assets/pwa/icon-192.png, icon-512.png

Como integrar (sem alterar credenciais/back-end):
1) Suba TODOS os arquivos na RAIZ do seu projeto /.
2) Adicione na tag <head> do admin.php:
   <link rel="manifest" href="/manifest.webmanifest">
   <link rel="stylesheet" href="/theme.css">
   <script defer src="/theme.js"></script>
   <script defer src="/a2hs.js"></script>

3) Registre o service worker (opcional, melhora A2HS/offline). Antes do </body> do admin.php:
   <script>
     if ('serviceWorker' in navigator) {
       navigator.serviceWorker.register('/sw.js').catch(console.warn);
     }
   </script>

4) Garanta que o elemento <html> receba a classe de tema automaticamente (theme.js cuida disso).
   Se quiser forçar inicialmente: <html class="theme-light"> ou "theme-dark".

5) Dashboard: inclua/importe o conteúdo de dashboard.php dentro da área principal do seu admin,
   ou configure seu roteador para que ?route=dashboard carregue esse arquivo.

6) O botão "Adicionar ao celular" aparece quando suportado.
   Para iOS/Safari mostramos uma dica manual (data-a2hs-tip).

Pronto. Nada aqui altera suas credenciais, queries ou lógica existente.
