<?php
session_start(); // Iniciar la sesión si aún no se ha iniciado
$email=$_POST["email"];
// Verificar si existe la variable de sesión para contar intentos por usuario
if (!isset($_SESSION['login_attempts'][$email])) {
    $_SESSION['login_attempts'][$email] = 1;
} else {
    // Incrementar el contador de intentos
    $_SESSION['login_attempts'][$email]++;
}

// Verificar el número de intentos permitidos
$max_attempts = 3;

if ($_SESSION['login_attempts'][$email] > $max_attempts) {
    // Implementar medidas adicionales, como bloquear el acceso o mostrar un mensaje de error
   // echo "Demasiados intentos. Por favor, inténtalo más tarde.";
   session_unset(); // Limpiar variables de sesión
   session_destroy(); // Destruir la sesión actual
   header("Location: https://esforzados.com/index.php?x=101"); 
        exit();
    // Puedes añadir aquí código adicional, como bloquear la cuenta temporalmente
    
} else {
    // Proceder con el intento de inicio de sesión
    // Tu lógica de validación y autenticación aquí

//ini_set('display_errors', 1);
//ini_set('error_reporting', E_ALL);
// Conexión a la base de datos (reemplaza con tus datos de conexión)
require_once __DIR__ . '/app_bootstrap.php';

// Verifica si los campos "email" y "password" están definidos en la solicitud POST
if (isset($_POST["email"]) && isset($_POST["password"])) {
    // Obtener valores del formulario
    $email = $_POST["email"];
    $password = $_POST["password"];

if (!preg_match("/[',\s%()]/", $email) && !preg_match("/[',\s%()]/", $password)) {
    // Procede con el uso de las variables de manera segura
    // Tu lógica de autenticación o manipulación de datos aquí

    // Consulta para obtener el ID del usuario, el hash de la contraseña y el rol
    $sql = "SELECT id, password, userstatus, role FROM Users WHERE email = ?";
    $stmt = $db_connection->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($userId, $storedHash, $userstatus, $userRole);
    $stmt->fetch();
    $stmt->close();

    // Verifica si se encontró un usuario con ese correo electrónico y si la contraseña no es nula
    if ($userId && $storedHash !== null && password_verify($password, $storedHash) && $userstatus == 'Activo') {
        // Contraseña válida, inicio de sesión exitoso
        $_SESSION['usuario'] = $email; // Almacena el nombre de usuario en la variable de sesión
        $_SESSION['user_id'] = $userId; // Almacena el user_id en la variable de sesión
        $_SESSION['role'] = $userRole;
        
        $_SESSION['show_counts'] = false;
        $_SESSION['show_metas'] = false;
        $_SESSION['media_hidden'] = true;
        $_SESSION['show_filters'] = true;
        
        // Inserta el registro en la tabla AccessControl utilizando el ID del usuario
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $action = "Inicio de Sesión"; // Cambia esto según tus necesidades
        $action_details = "Usuario AWS OTP con el rol de $userRole accedió.";
        $sql2 = "INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details)
                VALUES (?, NOW(), ?, ?, ?)";
        $stmt2 = $db_connection->prepare($sql2);
        $stmt2->bind_param("isss", $userId, $action, $ip_address, $action_details);

        if ($stmt2->execute()) {
            // Registro de acceso exitoso

            // Verificar el rol del usuario y redirigirlo en consecuencia
            if ($userRole == 'Administración' || $userRole == 'Soporte') {
                // Usuario con rol de administrador
                header("Location: chat.php"); // Redirige al panel de administrador
            } 
            exit();
        } else {
            echo "Error al registrar el acceso: " . $stmt2->error;
        }
    } else {
        // Credenciales incorrectas, redirige al usuario de vuelta a la página de inicio de sesión
         if (!password_verify($password, $storedHash)) {
        header("Location: 303.html"); // Cambia esto con la ruta correcta
        exit();
         }
         // Inactivo
          if ($userId && $storedHash !== null && password_verify($password, $storedHash) && $userstatus != 'Activo') {
            header("Location: 202.html"); // Cambia esto con la ruta correcta
        exit();  
          }
    }
    
} else {
    // Manejo de error o rechazo de datos inseguros
    //echo "Datos no válidos.";
    header("Location: https://esforzados.com/index.php?x=100"); 
    exit();
}
    
} else {
    // Si los campos "email" y "password" no están definidos en la solicitud POST
    header("Location: index.php"); // Redirige de vuelta a la página de inicio de sesión
    exit();
}
}
?>
