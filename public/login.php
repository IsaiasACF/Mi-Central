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
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if (Csrf::validate($csrfToken)) {
        try {
            $auth = new AuthService(Connection::get());

            if ($auth->attemptLogin($username, $password, ClientIp::current())) {
                header('Location: /index.php', true, 302);
                exit;
            }
        } catch (\Throwable $exception) {
            error_log('Login failed due to an internal error: ' . $exception->getMessage());
        }
    }

    $error = 'Credenciales invalidas o acceso temporalmente bloqueado.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar - Mi Central</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-page">
    <main class="login-panel">
        <h1>Mi Central</h1>
        <?php if ($error !== ''): ?>
            <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
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
    </main>
</body>
</html>
