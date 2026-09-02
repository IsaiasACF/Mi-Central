<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Http\SecurityHeaders;
use App\Http\Session;
use App\Services\AuthService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo 'Metodo no permitido.';
    exit;
}

$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

if (!Csrf::validate($csrfToken)) {
    http_response_code(400);
    echo 'Solicitud invalida.';
    exit;
}

AuthService::logout();

header('Location: /login.php', true, 302);
exit;
