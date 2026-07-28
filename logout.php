<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';
Auth::logout();
// Expulsa o bfcache e o storage do navegador ao sair, para que o botao "voltar"
// nao reexiba paginas autenticadas depois do logout (mitigacao do bfcache).
// Requer contexto seguro (HTTPS); ignorado em HTTP local, sem efeito colateral.
header('Clear-Site-Data: "cache", "storage"');
header('Location: ' . Auth::url('login.php'));
exit;
