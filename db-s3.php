<?php
$servidor  = "localhost";
$usuario   = "user_admin";
$clave     = "password";
$basedatos = "db-del-chat";

$db_connection = mysqli_connect($servidor, $usuario, $clave, $basedatos) or die(mysqli_error($db_connection));

if (!$db_connection) {
    die('No se ha podido conectar a la base de datos: ' . mysqli_connect_error());
}
?>