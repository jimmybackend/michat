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
<body><header class="top"><a href="chat.php" class="brand">MiChat</a><div><a href="trace_explorer.php">Trace Explorer</a><button id="refresh" type="button">Actualizar</button></div></header>
<main><aside><div class="aside-title"><h1>Task Center</h1><select id="status"><option value="">Todos los estados</option><option>ready</option><option>running</option><option>waiting_user</option><option>waiting_dependency</option><option>completed</option><option>failed</option><option>cancelled</option></select></div><div id="tasks" aria-live="polite"></div></aside>
<section id="detail" class="detail"><div class="empty"><strong>Selecciona una Task</strong><span>Consulta progreso, Steps, aprobaciones y trazabilidad.</span></div></section></main>
<template id="task-template"><button class="task" type="button"><span class="task-title"></span><small><b class="task-status"></b><time></time></small><i><span></span></i></button></template>
<script src="js/task-center.js" defer></script></body></html>
