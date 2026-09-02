<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\ClientIp;
use App\Http\Csrf;
use App\Http\SecurityHeaders;
use App\Http\Session;
use App\Services\AuthService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (Session::isAuthenticated()) {
    header('Location: /index.php', true, 302);
    exit;
}

$error = '';
$message = isset($_GET['registered']) ? 'Cuenta creada correctamente. Ya puedes iniciar sesion.' : '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if (Csrf::validate($csrfToken)) {
        try {
            $auth = new AuthService(Connection::get());

            $loginStatus = $auth->attemptLoginWithStatus($username, $password, ClientIp::current());

            if ($loginStatus === 'success') {
                header('Location: /index.php', true, 302);
                exit;
            }

            $error = match ($loginStatus) {
                AuthService::LOGIN_INACTIVE => 'Tu cuenta esta desactivada.',
                default => 'Credenciales invalidas o acceso temporalmente bloqueado.',
            };
        } catch (\Throwable $exception) {
            error_log('Login failed due to an internal error: ' . $exception->getMessage());
            $error = 'Credenciales invalidas o acceso temporalmente bloqueado.';
        }
    } else {
        $error = 'Credenciales invalidas o acceso temporalmente bloqueado.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/brand/icon%20mi-central.png">
    <title>Ingresar - Mi Central</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= (string) (filemtime(dirname(__DIR__) . '/public/assets/css/app.css') ?: '1') ?>">
</head>
<body class="login-page">
    <main class="login-panel">
        <h1 class="login-panel__brand">
            <img class="login-panel__logo" src="/brand/logo%20mi-central.png" alt="Mi Central">
        </h1>
        <?php if ($error !== ''): ?>
            <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <p class="login-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form action="/login.php" method="post">
            <?= Csrf::input() ?>
            <div>
                <label for="username">Usuario</label>
                <input id="username" name="username" type="text" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username">
            </div>
            <div>
                <label for="password">Contrasena</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <button type="submit">Ingresar</button>
        </form>
        <p class="login-secondary">No tienes cuenta? <a href="/register.php">Registrarse</a></p>
    </main>
</body>
</html>
