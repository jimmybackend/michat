<?php
declare(strict_types=1);

// Portada pública de Esforzados Chat.
// La autenticación continúa en psesion.php; esta página nunca valida credenciales.
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#081724">
  <meta name="description" content="Esforzados Chat: espacio privado de inteligencia artificial con Amazon Bedrock para conversaciones, proyectos y análisis de documentos.">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Esforzados Chat | Inteligencia artificial privada">
  <meta property="og:description" content="Conversaciones, proyectos y documentos con inteligencia artificial en un entorno privado.">
  <meta property="og:image" content="https://esforzados.com/assets/carousel-chat.png">
  <link rel="canonical" href="https://chat.esforzados.com/">
  <link rel="icon" href="ellogo.png" type="image/png">
  <title>Esforzados Chat | Acceso privado</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    :root { color-scheme: dark; --bg: #081724; --panel: #102738; --ink: #edf7fc; --muted: #b5c8d4; --accent: #65e4c2; }
    *, *::before, *::after { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; background: radial-gradient(circle at 50% 0, #173d51, var(--bg) 65%); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
    a { color: var(--accent); }
    a:hover { color: #b0ffe7; }
    .skip-link { position: absolute; z-index: 1000; top: -4rem; left: 1rem; padding: .6rem 1rem; background: #fff; color: #102738; }
    .skip-link:focus { top: 1rem; }
    .site-header { border-bottom: 1px solid rgba(125, 208, 220, .2); background: rgba(5, 18, 29, .75); }
    .header-inner { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 78px; }
    .brand { display: inline-flex; align-items: center; gap: .75rem; text-decoration: none; color: var(--ink); font-size: 1.2rem; font-weight: 750; letter-spacing: -.02em; }
    .brand:hover { color: var(--ink); text-decoration: none; }
    .brand img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
    .brand small { display: block; color: var(--muted); font-size: .7rem; font-weight: 400; letter-spacing: 0; }
    .btn-access { background: var(--accent); border: 1px solid var(--accent); border-radius: .65rem; color: #08232b; font-weight: 750; padding: .7rem 1.25rem; }
    .btn-access:hover, .btn-access:focus { background: #b0ffe7; border-color: #b0ffe7; color: #08232b; }
    .btn-outline-access { border: 1px solid rgba(101, 228, 194, .7); border-radius: .65rem; padding: .65rem 1rem; color: var(--ink); }
    .btn-outline-access:hover { background: rgba(101,228,194,.1); color: #fff; }
    main { padding: 2.25rem 0 3rem; }
    .hero { overflow: hidden; background: var(--panel); border: 1px solid rgba(105,202,220,.25); border-radius: 1.15rem; box-shadow: 0 22px 65px rgba(0,0,0,.28); }
    .hero-image { display: block; width: 100%; height: auto; background: #071422; }
    .hero-content { padding: clamp(1.25rem, 3.5vw, 2.5rem); }
    .eyebrow { font-size: .82rem; font-weight: 750; text-transform: uppercase; letter-spacing: .13em; color: var(--accent); }
    h1 { font-size: clamp(1.6rem, 3.5vw, 2.7rem); line-height: 1.14; font-weight: 800; margin: .45rem 0 1rem; }
    .lead-text { color: var(--muted); font-size: 1.04rem; line-height: 1.65; max-width: 850px; }
    .feature-list { display: flex; flex-wrap: wrap; gap: .6rem; padding: 0; margin: 1.25rem 0 1.7rem; list-style: none; }
    .feature-list li { border: 1px solid rgba(115,202,211,.28); border-radius: 999px; background: rgba(0,0,0,.16); padding: .45rem .85rem; font-size: .88rem; color: #dcecf2; }
    .hero-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
    .privacy-note { margin: 1.15rem 0 0; font-size: .84rem; color: var(--muted); }
    .site-footer { border-top: 1px solid rgba(125,208,220,.18); padding: 1.4rem 0; color: var(--muted); font-size: .86rem; }
    .site-footer .container { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem; }
    .modal-content { background: #102738; border: 1px solid rgba(101,228,194,.35); border-radius: 1rem; color: var(--ink); }
    .modal-header, .modal-footer { border-color: rgba(125,208,220,.2); }
    .modal-title { font-weight: 750; }
    .modal .close { color: #fff; opacity: .85; text-shadow: none; }
    .modal .form-control { background: #071a28; border: 1px solid #4c7282; color: #fff; min-height: 44px; }
    .modal .form-control:focus { background: #071a28; color: #fff; border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(101,228,194,.15); }
    .modal .form-control::placeholder { color: #a6bac7; }
    .modal .form-text { color: var(--muted); }
    @media (max-width: 575.98px) { .header-inner { min-height: 70px; } .brand { font-size: 1rem; } .brand img { width: 37px; height: 37px; } .header-inner .btn-access { padding: .55rem .7rem; font-size: .85rem; } main { padding-top: 1.1rem; } }
  </style>
</head>
<body>
  <a class="skip-link" href="#contenido">Saltar al contenido</a>
  <header class="site-header">
    <div class="container header-inner">
      <a class="brand" href="https://esforzados.com/" aria-label="Volver a Esforzados">
        <img src="ellogo.png" alt="" width="44" height="44">
        <span>Esforzados Chat<small>Inteligencia artificial · Acceso privado</small></span>
      </a>
      <button type="button" class="btn btn-access" data-toggle="modal" data-target="#loginModal">Iniciar sesión</button>
    </div>
  </header>

  <main id="contenido">
    <div class="container">
      <section class="hero" aria-labelledby="heroTitle">
        <!-- Imagen original servida desde el portal Esforzados; no se duplica en MiChat. -->
        <img class="hero-image" src="https://esforzados.com/assets/carousel-chat.png"
             alt="Esforzados Chat: espacio privado de conversaciones con inteligencia artificial, proyectos y documentos conectados a ArcadeCloud Drive."
             width="1916" height="821" fetchpriority="high">
        <div class="hero-content">
          <p class="eyebrow">Plataforma privada de Esforzados</p>
          <h1 id="heroTitle">Conversa, investiga y trabaja con inteligencia artificial</h1>
          <p class="lead-text">Esforzados Chat reúne conversaciones con IA de Amazon Bedrock, proyectos y herramientas de trabajo con documentos en un espacio para usuarios autorizados.</p>
          <ul class="feature-list" aria-label="Funcionalidades">
            <li>Amazon Bedrock</li>
            <li>Conversaciones y proyectos</li>
            <li>Análisis de documentos</li>
            <li>Integración con ArcadeCloud Drive</li>
          </ul>
          <div class="hero-actions">
            <button class="btn btn-access" type="button" data-toggle="modal" data-target="#loginModal">Acceso privado a MiChat</button>
            <a class="btn btn-outline-access" href="https://esforzados.com/">Volver a Esforzados</a>
          </div>
          <p class="privacy-note">El acceso al chat requiere una cuenta autorizada. No se ofrecen registros públicos desde esta página.</p>
        </div>
      </section>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <span>Servidor privado de Esforzados · Chat</span>
      <span>Soporte: <a href="mailto:soporte@esforzados.com">soporte@esforzados.com</a></span>
    </div>
  </footer>

  <div class="modal fade" id="loginModal" tabindex="-1" role="dialog" aria-labelledby="loginModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h2 class="modal-title h5 mb-1" id="loginModalTitle">Acceso privado a Esforzados Chat</h2>
            <p class="form-text mb-0">Ingresa las credenciales de tu cuenta autorizada.</p>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <form action="psesion.php" method="post">
          <div class="modal-body">
            <div class="form-group">
              <label for="email">Correo electrónico</label>
              <input class="form-control" id="email" name="email" type="email" maxlength="255"
                     autocomplete="username" placeholder="correo@ejemplo.com" required>
            </div>
            <div class="form-group mb-0">
              <label for="password">Contraseña</label>
              <input class="form-control" id="password" name="password" type="password"
                     autocomplete="current-password" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-access btn-block">Iniciar sesión</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function () {
      'use strict';
      $('#loginModal').on('shown.bs.modal', function () {
        document.getElementById('email').focus();
      });
    }());
  </script>
</body>
</html>
