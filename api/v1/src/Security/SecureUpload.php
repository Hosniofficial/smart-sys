<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Validates untrusted uploads by inspecting their content, never their client-supplied name.
 *
 * The extension of the stored file is derived from the sniffed MIME type so a client can never
 * choose an executable extension (php, phtml, phar, ...). Only an explicit allowlist is accepted.
 */
final class SecureUpload
{
    public const MAX_BYTES = 10485760; // 10 MB

    /** Sniffed MIME type => canonical stored extension. */
    private const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];

    /** Extension => response Content-Type (used when serving files back). */
    private const SERVE_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
    ];

    /**
     * Inspect an uploaded file and return its canonical extension and MIME type.
     *
     * @return array{extension: string, mime: string, size: int}
     * @throws InvalidArgumentException when the file is empty, too large, or not an allowed type
     */
    public static function inspect(UploadedFileInterface $file): array
    {
        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new InvalidArgumentException('الملف فارغ.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('حجم الملف يتجاوز الحد المسموح (10 ميجابايت).');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(8192);
        $stream->rewind();

        return self::inspectBytes($head, $size);
    }

    /**
     * @return array{extension: string, mime: string, size: int}
     * @throws InvalidArgumentException
     */
    public static function inspectBytes(string $head, int $size): array
    {
        if ($head === '') {
            throw new InvalidArgumentException('الملف فارغ.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            throw new InvalidArgumentException('نوع الملف غير مسموح. المسموح: صور (JPG, PNG, WEBP, GIF) وملفات PDF.');
        }

        return ['extension' => self::ALLOWED[$mime], 'mime' => $mime, 'size' => $size];
    }

    /** Content-Type to use when serving a stored file, or null when the extension is not allowed. */
    public static function contentTypeForExtension(string $extension): ?string
    {
        return self::SERVE_TYPES[strtolower($extension)] ?? null;
    }

    /** True only for a plain file name (no directories, no hidden/dot files, no traversal). */
    public static function isSafeStoredName(string $name): bool
    {
        return $name !== ''
            && $name === basename($name)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1
            && !str_contains($name, '..');
    }
}
