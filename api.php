<?php
declare(strict_types=1);

/**
 * API JSON da aplicação. Pedidos: api.php?action=<ação>&c=<empresa>[&...]
 * Escritas por POST com corpo JSON (ou multipart para ficheiros) e cabeçalho X-Requested-With: IDEIA.
 */

require __DIR__ . '/src/bootstrap.php';

use Ideia\Api;

Api::handle();
