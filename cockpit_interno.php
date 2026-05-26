<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'admin.manage'], false);

header('Location: admin.php?tab=cockpit', true, 302);
exit;
