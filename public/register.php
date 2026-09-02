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

$auth = new AuthService(Connection::get());
$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $ipAddress = ClientIp::current();

    if (!Csrf::validate($csrfToken)) {
        $error = 'Solicitud invalida. Intenta nuevamente.';
    } elseif ($auth->isRegistrationTemporarilyBlocked($ipAddress)) {
        $error = 'No se pudo procesar la solicitud en este momento.';
    } else {
        $auth->recordRegistrationAttempt($ipAddress);
        $normalizedUsername = AuthService::normalizeUsername($username);

        if (!AuthService::isValidUsername($normalizedUsername)) {
            $error = 'El nombre de usuario debe tener entre 3 y 64 caracteres y usar solo letras, numeros, punto, guion o guion bajo.';
        } elseif (strlen($password) < 12) {
            $error = 'La contrasena debe tener al menos 12 caracteres.';
        } elseif ($password !== $passwordConfirmation) {
            $error = 'La confirmacion de contrasena no coincide.';
        } elseif ($auth->usernameExists($normalizedUsername)) {
            $error = 'Ese nombre de usuario no esta disponible.';
        } else {
            try {
                $auth->createUser($normalizedUsername, $password);
                header('Location: /login.php?registered=1', true, 303);
                exit;
            } catch (\PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'Ese nombre de usuario no esta disponible.';
                } else {
                    error_log('Registration failed due to a database error: ' . $exception->getMessage());
                    $error = 'No se pudo procesar la solicitud en este momento.';
                }
            } catch (\Throwable $exception) {
                error_log('Registration failed due to an internal error: ' . $exception->getMessage());
                $error = 'No se pudo procesar la solicitud en este momento.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/brand/icon%20mi-central.png">
    <title>Registrarse - Mi Central</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= (string) (filemtime(dirname(__DIR__) . '/public/assets/css/app.css') ?: '1') ?>">
</head>
<body class="login-page">
    <main class="login-panel">
        <h1>Registrarse</h1>
        <?php if ($error !== ''): ?>
            <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form action="/register.php" method="post">
            <?= Csrf::input() ?>
            <div>
                <label for="username">Nombre de usuario</label>
                <input id="username" name="username" type="text" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="username">
            </div>
            <div>
                <label for="password">Contrasena</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </div>
            <div>
                <label for="password_confirmation">Confirmar contrasena</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button type="submit">Crear cuenta</button>
        </form>
        <p class="login-secondary">Ya tienes cuenta? <a href="/login.php">Iniciar sesion</a></p>
    </main>
</body>
</html>
