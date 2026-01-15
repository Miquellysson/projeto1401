<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config.php';
require __DIR__ . '/lib/utils.php';
require __DIR__ . '/lib/password_reset.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$token = trim($_GET['token'] ?? '');
$cfg = cfg();
$storeName = $cfg['store']['name'] ?? 'Get Power Research';
$themeColor = setting_get('theme_color', '#2060C8');

$tokenValid = $token !== '' && pr_validate_token($token);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Nova senha — <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="<?= asset_url('assets/theme.css'); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{background:#f5f7fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{background:#fff;max-width:460px;width:100%;padding:32px;border-radius:20px;box-shadow:0 25px 60px -35px rgba(32,96,200,.25);}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:24px;}
    .brand-logo{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,#2060C8,#4F88FF);color:#fff;font-size:20px;}
    .input{width:100%;border:1px solid #d1d5db;border-radius:14px;padding:12px 14px;font-size:15px;transition:border .2s,box-shadow .2s;}
    .input:focus{outline:none;border-color:<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>;box-shadow:0 0 0 3px rgba(32,96,200,.15);}
    .btn{border:none;border-radius:14px;padding:12px 16px;font-weight:600;cursor:pointer;}
    .btn-primary{background:<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>;color:#fff;box-shadow:0 18px 30px -22px rgba(32,96,200,.75);width:100%;}
    .btn-primary:disabled{opacity:.6;cursor:not-allowed;}
    .strength{height:8px;border-radius:8px;background:#e5e7eb;margin-top:8px;overflow:hidden;}
    .strength-bar{height:100%;width:0%;background:#ef4444;transition:width .3s,background .3s;}
    .badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:12px;font-size:12px;background:#f3f4f6;color:#4b5563;margin-top:10px;}
    .error{color:#dc2626;font-size:13px;margin-top:8px;}
    .success{color:#16a34a;font-size:14px;margin-top:18px;background:#ecfdf3;border-radius:12px;padding:12px;border:1px solid #bbf7d0;}
    .hidden{display:none!important;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <div class="brand-logo"><i class="fa-solid fa-key"></i></div>
      <div>
        <h1 class="text-xl font-semibold m-0">Definir nova senha</h1>
        <p class="text-sm text-gray-500 m-0">Crie uma senha forte e exclusiva para sua conta.</p>
      </div>
    </div>

    <?php if (!$tokenValid): ?>
      <div class="error">
        Link inválido ou expirado. Solicite novamente em <a href="forgot_password.php" class="text-brand-600 hover:underline">Esqueci minha senha</a>.
      </div>
    <?php else: ?>
      <form id="reset-form" class="space-y-4" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

        <label class="block text-sm font-medium text-gray-700" for="password">Nova senha</label>
        <div class="relative">
          <input class="input" type="password" id="password" name="password" required placeholder="••••••••">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 cursor-pointer text-gray-400" id="toggle-pass">
            <i class="fa-solid fa-eye"></i>
          </span>
        </div>
        <div class="strength">
          <div class="strength-bar" id="strength-bar"></div>
        </div>
        <div class="badge" id="strength-label">Senha fraca</div>

        <label class="block text-sm font-medium text-gray-700 mt-4" for="confirm_password">Confirmar nova senha</label>
        <div class="relative">
          <input class="input" type="password" id="confirm_password" name="confirm_password" required placeholder="Repita a senha">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 cursor-pointer text-gray-400" id="toggle-confirm">
            <i class="fa-solid fa-eye"></i>
          </span>
        </div>
        <p id="match-error" class="error hidden">As senhas não conferem.</p>

        <ul class="text-sm text-gray-500 space-y-1 pt-2">
          <li><i class="fa-solid fa-check text-green-500"></i> Pelo menos 8 caracteres</li>
          <li><i class="fa-solid fa-check text-green-500"></i> Letras maiúsculas e minúsculas</li>
          <li><i class="fa-solid fa-check text-green-500"></i> Pelo menos um número</li>
        </ul>

        <button type="submit" class="btn btn-primary" id="submit-btn">
          <span id="btn-text"><i class="fa-solid fa-lock"></i> Atualizar senha</span>
          <span id="btn-loading" class="hidden"><i class="fa-solid fa-spinner fa-spin"></i> Salvando...</span>
        </button>
      </form>

      <p id="success-feedback" class="success hidden">
        Senha atualizada com sucesso! Você será redirecionado para o login em instantes.
      </p>
      <p id="error-feedback" class="error hidden"></p>
    <?php endif; ?>

    <p class="hint">
      <a href="admin.php?route=login" class="text-brand-600 hover:underline text-sm">
        <i class="fa-solid fa-arrow-left"></i> Voltar para login
      </a>
    </p>
  </div>

<script>
<?php if ($tokenValid): ?>
  const form = document.getElementById('reset-form');
  const passwordInput = document.getElementById('password');
  const confirmInput = document.getElementById('confirm_password');
  const matchError = document.getElementById('match-error');
  const strengthBar = document.getElementById('strength-bar');
  const strengthLabel = document.getElementById('strength-label');
  const submitBtn = document.getElementById('submit-btn');
  const btnText = document.getElementById('btn-text');
  const btnLoading = document.getElementById('btn-loading');
  const successFeedback = document.getElementById('success-feedback');
  const errorFeedback = document.getElementById('error-feedback');

  function toggleVisibility(input, toggle) {
    toggle.addEventListener('click', () => {
      const isPassword = input.getAttribute('type') === 'password';
      input.setAttribute('type', isPassword ? 'text' : 'password');
      toggle.innerHTML = isPassword ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
    });
  }
  toggleVisibility(passwordInput, document.getElementById('toggle-pass'));
  toggleVisibility(confirmInput, document.getElementById('toggle-confirm'));

  function evaluateStrength(password) {
    let score = 0;
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return score;
  }

  passwordInput.addEventListener('input', () => {
    const score = evaluateStrength(passwordInput.value);
    const percent = (score / 5) * 100;
    strengthBar.style.width = percent + '%';
    let label = 'Senha fraca';
    let color = '#ef4444';
    if (score >= 4) { label = 'Senha forte'; color = '#10b981'; }
    else if (score >= 3) { label = 'Senha média'; color = '#f59e0b'; }
    strengthBar.style.background = color;
    strengthLabel.textContent = label;
  });

  confirmInput.addEventListener('input', () => {
    const match = confirmInput.value === passwordInput.value;
    matchError.classList.toggle('hidden', match);
    confirmInput.classList.toggle('border-red-500', !match);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const match = confirmInput.value === passwordInput.value;
    if (!match) {
      matchError.classList.remove('hidden');
      return;
    }
    const formData = new FormData(form);
    submitBtn.disabled = true;
    btnText.classList.add('hidden');
    btnLoading.classList.remove('hidden');
    successFeedback.classList.add('hidden');
    errorFeedback.classList.add('hidden');

    try {
      const res = await fetch('api/reset_password_submit.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Não foi possível redefinir a senha.');
      }
      successFeedback.classList.remove('hidden');
      setTimeout(() => {
        window.location.href = 'admin.php?route=login';
      }, 2500);
    } catch (error) {
      errorFeedback.textContent = error.message;
      errorFeedback.classList.remove('hidden');
      submitBtn.disabled = false;
      btnText.classList.remove('hidden');
      btnLoading.classList.add('hidden');
    }
  });
<?php endif; ?>
</script>
</body>
</html>
