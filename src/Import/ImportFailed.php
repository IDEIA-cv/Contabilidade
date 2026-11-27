<?php
declare(strict_types=1);

namespace Ideia\Import;

/** Lançada para anular a transação de importação quando há erros (nada é gravado). */
final class ImportFailed extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('Importação com erros: nada foi gravado.');
    }
}
