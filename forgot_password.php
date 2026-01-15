<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config.php';
require __DIR__ . '/lib/utils.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$cfg = cfg();
$storeName = $cfg['store']['name'] ?? 'Get Power Research';
$themeColor = setting_get('theme_color', '#2060C8');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Recuperar senha — <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="<?= asset_url('assets/theme.css'); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background:#f3f6fc;min-height:100vh;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:24px;}
    .card{background:#fff;max-width:420px;width:100%;padding:32px;border-radius:20px;box-shadow:0 25px 60px -35px rgba(32,96,200,.25);}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:24px;}
    .brand-logo{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,#2060C8,#4F88FF);color:#fff;font-size:20px;}
    .input{width:100%;border:1px solid #d1d5db;border-radius:14px;padding:12px 14px;font-size:15px;transition:border .2s,box-shadow .2s;}
    .input:focus{outline:none;border-color:<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>;box-shadow:0 0 0 3px rgba(32,96,200,.15);}
    .btn{border:none;border-radius:14px;padding:12px 16px;font-weight:600;cursor:pointer;transition:transform .2s,box-shadow .2s;}
    .btn-primary{background:<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>;color:#fff;box-shadow:0 18px 30px -22px rgba(32,96,200,.75);}
    .btn-primary:hover{transform:-translateY(1px);box-shadow:0 24px 35px -25px rgba(32,96,200,.85);}
    .btn:disabled{opacity:.6;cursor:not-allowed;box-shadow:none;}
    .hint{font-size:13px;color:#6b7280;margin-top:18px;}
    .error{color:#dc2626;font-size:13px;margin-top:8px;}
    .success{color:#16a34a;font-size:14px;margin-top:18px;background:#ecfdf3;border-radius:12px;padding:12px;border:1px solid #bbf7d0;}
    .hidden{display:none!important;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <div class="brand-logo"><i class="fa-solid fa-lock-open"></i></div>
      <div>
        <h1 class="text-xl font-semibold m-0">Recuperar senha</h1>
        <p class="text-sm text-gray-500 m-0">Informe seu e-mail para receber o link de redefinição.</p>
      </div>
    </div>

    <form id="forgot-form" class="space-y-4" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label class="block text-sm font-medium text-gray-700" for="email">E-mail</label>
      <div class="relative">
        <input class="input" type="email" id="email" name="email" required placeholder="seuemail@dominio.com">
        <span id="email-icon" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fa-solid fa-envelope"></i></span>
      </div>
      <p id="email-error" class="error hidden">Informe um e-mail válido.</p>

      <button type="submit" class="btn btn-primary w-full" id="submit-btn">
        <span class="flex items-center justify-center gap-2">
          <span id="btn-text"><i class="fa-solid fa-paper-plane"></i> Enviar link</span>
          <span id="btn-loading" class="hidden"><i class="fa-solid fa-spinner fa-spin"></i> Enviando...</span>
        </span>
      </button>
    </form>

    <p id="feedback" class="success hidden mt-4">
      Se o e-mail estiver cadastrado, enviaremos um link com instruções em instantes.
    </p>
    <p id="error-feedback" class="error hidden mt-4"></p>

    <p class="hint">
      <a href="admin.php?route=login" class="text-brand-600 hover:underline text-sm">
        <i class="fa-solid fa-arrow-left"></i> Voltar para login
      </a>
    </p>
  </div>

  <script>
    const form = document.getElementById('forgot-form');
    const emailInput = document.getElementById('email');
    const emailError = document.getElementById('email-error');
    const feedback = document.getElementById('feedback');
    const errorFeedback = document.getElementById('error-feedback');
    const submitBtn = document.getElementById('submit-btn');
    const btnText = document.getElementById('btn-text');
    const btnLoading = document.getElementById('btn-loading');

    function setLoading(state) {
      submitBtn.disabled = state;
      btnText.classList.toggle('hidden', state);
      btnLoading.classList.toggle('hidden', !state);
    }

    emailInput.addEventListener('input', () => {
      const value = emailInput.value.trim();
      const regex = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
      const valid = regex.test(value);
      emailError.classList.toggle('hidden', valid);
      emailInput.classList.toggle('border-red-500', !valid);
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      setLoading(true);
      feedback.classList.add('hidden');
      errorFeedback.classList.add('hidden');

      try {
        const res = await fetch('api/forgot_password_request.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Não foi possível enviar o e-mail.');
        }
        feedback.classList.remove('hidden');
        form.reset();
      } catch (error) {
        errorFeedback.textContent = error.message;
        errorFeedback.classList.remove('hidden');
      } finally {
        setLoading(false);
      }
    });
  </script>
</body>
</html>
