<?php

declare(strict_types=1);

/**
 * O contrato de isolamento por aba agora é exercitado em banco SQLite isolado.
 * Assim o guard não depende de contexto legado, empresa padrão ou migration já
 * aplicada no banco apontado pelo ambiente.
 */
require dirname(__DIR__, 2) . '/tests/run_tenant_context_r1.php';
