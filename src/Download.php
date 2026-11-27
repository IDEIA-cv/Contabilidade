<?php
declare(strict_types=1);

namespace Ideia;

/** Resposta de ficheiro para descarregar (em vez de JSON). */
final class Download
{
    public function __construct(public string $content, public string $filename, public string $type, public bool $inline = false)
    {
    }

    public function send(): void
    {
        header('Content-Type: ' . $this->type);
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', str_replace('"', '', $this->filename));
        header('Content-Disposition: ' . ($this->inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($this->filename));
        header('X-Content-Type-Options: nosniff');
        if (str_starts_with($this->type, 'text/html')) {
            header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
        }
        header('Content-Length: ' . strlen($this->content));
        header('Cache-Control: no-store');
        echo $this->content;
    }
}
