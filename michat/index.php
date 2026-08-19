
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceder</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/chat2.css">
    <link rel="icon" href="../assets/img/icono.png" type="image/x-icon">

    <style>
        #zona-subida {
            position: sticky;
            top: 0;
            z-index: 800;
            padding-top: 20px;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .login-card {
            max-width: 420px;
            width: 100%;
        }

        .login-logo {
            max-height: 120px;
            width: 120px;
            object-fit: cover;
        }
    </style>
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

    <div class="container">
        <div class="login-wrapper">
            <div class="card shadow-lg p-4 login-card">
                <div class="text-center mb-4">
                    <img src="ellogo.png" alt="Logo Arcade" class="rounded-circle shadow mb-3 login-logo">
                </div>

                <form action="psesion.php" method="POST">
                    <div class="form-group">
                        <label for="email">Correo electrónico</label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control"
                            placeholder="Correo Electrónico"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            placeholder="Contraseña"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        Iniciar sesión
                    </button>
                </form>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>