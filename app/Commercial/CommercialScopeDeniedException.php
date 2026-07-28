<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use RuntimeException;

/**
 * Recusa de escopo comercial — vendedor ou canal fora do autorizado.
 *
 * O endpoint traduz para HTTP 403 com mensagem GENÉRICA: nunca revela qual
 * vendedor/canal existe ou por que foi negado (isso já seria informação). A
 * mensagem específica fica só nesta exceção, para log interno.
 */
final class CommercialScopeDeniedException extends RuntimeException
{
}
