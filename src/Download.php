<?php
declare(strict_types=1);

namespace Ideia;

/** Resposta de ficheiro para descarregar (em vez de JSON). */
final class Download
{
    public function __construct(public string $content, public string $filename, public string $type)
    {
    }

    public function send(): void
    {
        header('Content-Type: ' . $this->type);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $this->filename) . '"');
        header('Content-Length: ' . strlen($this->content));
        header('Cache-Control: no-store');
        echo $this->content;
    }
}
