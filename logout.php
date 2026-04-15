<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';
Auth::logout();
header('Location: ' . Auth::url('login.php'));
exit;
