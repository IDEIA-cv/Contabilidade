<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$v = IDEIA_VERSION;
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IDEIA Contabilidade</title>
  <link rel="icon" href="assets/img/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
  <script src="assets/js/theme.js?v=<?= $v ?>"></script>
</head>
<body>
  <div class="app">
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="#/">
        <img src="assets/img/icon.svg" alt="" width="28" height="28">
        <span>IDEIA <b>Contabilidade</b></span>
      </a>
      <div class="company-switch" id="company-switch"></div>
      <nav id="nav"></nav>
      <div class="sidebar-foot"><label class="theme-pick">Tema <select id="theme-select"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label>
        v<?= $v ?> · dados guardados neste computador <button id="logout" class="link-btn" hidden>Sair</button></div>
    </aside>
    <main class="main">
      <header class="topbar">
        <button class="icon-btn menu-btn" id="menu-btn" aria-label="Menu">☰</button>
        <div class="crumbs" id="crumbs"></div>
        <div class="topbar-right" id="topbar-right"></div>
      </header>
      <section class="content" id="view" tabindex="-1"></section>
    </main>
  </div>
  <div id="modal-root"></div>
  <div id="toasts" aria-live="polite"></div>
  <script type="module" src="assets/js/main.js?v=<?= $v ?>"></script>
</body>
</html>
