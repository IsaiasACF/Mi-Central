<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Http\Session;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

Session::start($config['app']['session']);

$token = Csrf::token();

if ($token === '' || !Csrf::validate($token)) {
    fwrite(STDERR, "CSRF: FAILED\n");
    exit(1);
}

if (Csrf::validate('invalid-token')) {
    fwrite(STDERR, "CSRF: FAILED\n");
    exit(1);
}

Session::destroy();

echo "CSRF: OK\n";
exit(0);
