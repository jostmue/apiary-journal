<?php
require_once __DIR__ . '/includes/auth.php';

// Wenn bereits angemeldet, direkt zur App
if (current_user()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Anmeldung – Imkerei-Tagebuch</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-logo">🐝</div>
        <h1>Imkerei-Tagebuch</h1>
        <p class="login-sub">Bitte melde dich an</p>
        <form id="loginForm" autocomplete="on">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit">Anmelden</button>
            <p id="loginError" class="error-msg" hidden></p>
        </form>
        <p class="login-hint">Standard: <code>admin</code> / <code>admin123</code> – bitte nach dem ersten Login ändern.</p>
    </div>

<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('loginError');
    errEl.hidden = true;
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    try {
        const res = await fetch('api.php?res=auth&action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            errEl.textContent = data.error || 'Anmeldung fehlgeschlagen.';
            errEl.hidden = false;
            return;
        }
        window.location.href = 'index.php';
    } catch (err) {
        errEl.textContent = 'Verbindung zum Server fehlgeschlagen.';
        errEl.hidden = false;
    }
});
</script>
</body>
</html>
