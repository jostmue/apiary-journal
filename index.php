<?php
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Imkerei-Tagebuch</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div id="app">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <span class="logo">🐝</span>
            <span class="app-title">Imkerei-<br>Tagebuch</span>
        </div>
        <nav class="nav">
            <a href="#/dashboard" data-view="dashboard" class="nav-link"><span class="ic">📊</span> Übersicht</a>
            <a href="#/standorte" data-view="standorte" class="nav-link"><span class="ic">📍</span> Standorte</a>
            <a href="#/voelker" data-view="voelker" class="nav-link"><span class="ic">🐝</span> Völker</a>
            <a href="#/durchsichten" data-view="durchsichten" class="nav-link"><span class="ic">📋</span> Durchsichten</a>
            <a href="#/fuetterungen" data-view="fuetterungen" class="nav-link"><span class="ic">🍯</span> Fütterungen</a>
            <a href="#/behandlungen" data-view="behandlungen" class="nav-link"><span class="ic">💊</span> Behandlungen</a>
            <a href="#/ernte" data-view="ernte" class="nav-link"><span class="ic">🫙</span> Ernte</a>
            <a href="#/aufgaben" data-view="aufgaben" class="nav-link"><span class="ic">✅</span> Aufgaben</a>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="#/benutzer" data-view="benutzer" class="nav-link"><span class="ic">👤</span> Benutzer</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <div class="user-avatar"><?= htmlspecialchars(mb_substr($user['name'], 0, 1)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="user-role"><?= $user['role'] === 'admin' ? 'Administrator' : 'Imker' ?></div>
                </div>
            </div>
            <button id="logoutBtn" class="btn-link">Abmelden</button>
        </div>
    </aside>

    <button id="menuToggle" class="menu-toggle" aria-label="Menü">☰</button>

    <main class="content">
        <div id="toast" class="toast" hidden></div>
        <div id="view" class="view"></div>
    </main>
</div>

<div id="modalOverlay" class="modal-overlay" hidden>
    <div class="modal" id="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Titel</h2>
            <button id="modalClose" class="btn-icon">✕</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script>
window.CURRENT_USER = <?= json_encode($user, JSON_UNESCAPED_UNICODE) ?>;
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
