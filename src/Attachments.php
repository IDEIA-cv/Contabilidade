<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Documentos anexos aos lançamentos (faturas digitalizadas, recibos, extratos).
 * Ficam dentro do ficheiro .ideia, por isso viajam com a cópia de segurança.
 * Num lançamento validado podem acrescentar-se anexos, mas não apagá-los (rasto de auditoria).
 */
final class Attachments
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'xml' => 'application/xml', 'csv' => 'text/csv', 'txt' => 'text/plain',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword',
    ];

    public static function list(Db $db, int $entryId): array
    {
        return $db->all('SELECT id, entry_id, filename, mime, size, sha256, created_at FROM entry_attachments WHERE entry_id = ? ORDER BY id', [$entryId]);
    }

    public static function add(Db $db, int $entryId, string $filename, string $content): array
    {
        $entry = $db->one('SELECT id, status, journal, number FROM entries WHERE id = ?', [$entryId]);
        if (!$entry) {
            throw new UserError('Lançamento não encontrado.');
        }
        $size = strlen($content);
        if ($size === 0) {
            throw new UserError('O ficheiro está vazio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new UserError('O anexo excede 10 MB. Reduza a resolução da digitalização ou divida o documento.');
        }
        $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', basename($filename)) ?? '') ?: 'documento';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) {
            throw new UserError('Tipo de ficheiro não aceite. Use PDF, imagem (JPG, PNG), XML, CSV, Excel ou Word.');
        }
        $sha = hash('sha256', $content);
        if ($db->value('SELECT 1 FROM entry_attachments WHERE entry_id = ? AND sha256 = ?', [$entryId, $sha])) {
            throw new UserError("Este ficheiro já está anexado ao lançamento ({$name}).");
        }
        $st = $db->pdo->prepare('INSERT INTO entry_attachments (entry_id, filename, mime, size, sha256, content, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->bindValue(1, $entryId, \PDO::PARAM_INT);
        $st->bindValue(2, $name);
        $st->bindValue(3, self::MIME[$ext]);
        $st->bindValue(4, $size, \PDO::PARAM_INT);
        $st->bindValue(5, $sha);
        $st->bindValue(6, $content, \PDO::PARAM_LOB);
        $st->bindValue(7, date('Y-m-d H:i:s')); // hora local, como validated_at
        $st->execute();
        $id = (int) $db->pdo->lastInsertId();
        $db->audit('anexo.adicionado', ['lancamento' => $entryId, 'ficheiro' => $name, 'sha256' => $sha]);
        return $db->one('SELECT id, entry_id, filename, mime, size, sha256, created_at FROM entry_attachments WHERE id = ?', [$id]);
    }

    /** @return array{0:string,1:string,2:string} [conteúdo, nome, mime] */
    public static function get(Db $db, int $id): array
    {
        $a = $db->one('SELECT filename, mime, content FROM entry_attachments WHERE id = ?', [$id]);
        if (!$a) {
            throw new UserError('Anexo não encontrado.');
        }
        return [(string) $a['content'], (string) $a['filename'], (string) $a['mime']];
    }

    public static function delete(Db $db, int $id): void
    {
        $a = $db->one('SELECT a.id, a.filename, a.entry_id, e.status FROM entry_attachments a JOIN entries e ON e.id = a.entry_id WHERE a.id = ?', [$id]);
        if (!$a) {
            throw new UserError('Anexo não encontrado.');
        }
        if ($a['status'] === 'validado') {
            throw new UserError('Não se pode retirar um anexo de um lançamento validado.');
        }
        $db->run('DELETE FROM entry_attachments WHERE id = ?', [$id]);
        $db->audit('anexo.removido', ['lancamento' => (int) $a['entry_id'], 'ficheiro' => $a['filename']]);
    }
}
