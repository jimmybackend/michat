<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/app_bootstrap.php';
if (empty($_SESSION['usuario']) || (int)($_SESSION['user_id'] ?? 0) < 1) { header('Location: ../index.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['csrf_token'];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>">
<title>Task Center · MiChat</title><link rel="stylesheet" href="css/task-center.css"></head>
<body><header class="top"><a href="chat.php" class="brand">MiChat</a><div><a href="chat.php">Chat</a><a href="trace_explorer.php">Trace Explorer</a><button id="refresh" type="button">Actualizar</button></div></header>
<div id="task-feedback" class="task-feedback" role="status" aria-live="polite" aria-atomic="true"></div>
<main id="workspace"><aside><div class="aside-title"><div><h1>Task Center</h1><p>Explora el trabajo por contexto.</p></div><div class="view-switch" role="group" aria-label="Vista de Tasks"><button id="view-list" type="button" aria-pressed="true">Lista</button><button id="view-board" type="button" aria-pressed="false">Tablero</button></div></div>
<form id="filters" class="filters" role="search"><label class="search" for="search">Buscar</label><input class="search-input" id="search" name="q" type="search" maxlength="200" placeholder="Título u objetivo"><label for="status">Estado</label><select id="status" name="status"><option value="">Todos</option><option>pending</option><option>ready</option><option>running</option><option>waiting_user</option><option>waiting_dependency</option><option>completed</option><option>failed</option><option>cancelled</option></select><label for="priority">Prioridad</label><select id="priority" name="priority"><option value="">Todas</option><option value="low">Baja</option><option value="normal">Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option></select><label for="project">Proyecto</label><select id="project" name="project_id"><option value="">Todos</option></select><label for="session">Sesión</label><select id="session" name="session_id"><option value="">Todas</option></select><button type="button" id="clear">Limpiar</button></form>
<div id="result-summary" class="result-summary" aria-live="polite"></div><div id="tasks" aria-live="polite" aria-busy="false"></div><section id="board" class="board" aria-label="Tablero operativo" aria-live="polite" aria-busy="false" hidden></section><nav class="pagination" aria-label="Paginación de Tasks"><button id="previous" type="button">Anterior</button><span id="page"></span><button id="next" type="button">Siguiente</button></nav></aside>
<section id="detail" class="detail" tabindex="-1" aria-busy="false"><div class="empty"><strong>Selecciona una Task</strong><span>Consulta progreso, Steps, aprobaciones y trazabilidad.</span></div></section></main>
<template id="task-template"><button class="task" type="button"><span class="task-title"></span><span class="task-context"></span><span class="task-operational"><strong class="task-situation"></strong><span class="task-step"></span><span class="task-dates"></span></span><small><b class="task-status"></b><em class="task-priority"></em><time></time></small><i><span></span></i></button></template>
<template id="board-card-template"><button class="board-card" type="button"><span class="board-title"></span><span class="board-context"></span><span class="board-meta"><b class="board-status"></b><em class="board-priority"></em></span><span class="board-situation"></span><span class="board-step"></span><span class="board-date"></span><i><span></span></i></button></template>
<script src="js/task-operational-context.js" defer></script><script src="js/task-center.js" defer></script></body></html>
