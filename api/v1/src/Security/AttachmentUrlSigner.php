<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Issues and verifies short-lived, tamper-proof download links for private attachments.
 *
 * The signature binds the attachment id, the owning tenant, and an expiry timestamp, so a link
 * cannot be reused for another file or tenant and stops working after it expires. This lets
 * plain <img>/<a> tags load private files without exposing the upload directory.
 */
final class AttachmentUrlSigner
{
    public const DEFAULT_TTL = 600;

    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Attachment URL signing secret must not be empty.');
        }
    }

    public static function fromEnvironment(): self
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');

        return new self($secret);
    }

    /** @return array{exp: int, sig: string} */
    public function sign(int $attachmentId, int $tenantId, ?int $now = null, int $ttl = self::DEFAULT_TTL): array
    {
        $exp = ($now ?? time()) + $ttl;

        return ['exp' => $exp, 'sig' => $this->signature($attachmentId, $tenantId, $exp)];
    }

    public function verify(int $attachmentId, int $tenantId, int $exp, string $sig, ?int $now = null): bool
    {
        if ($exp < ($now ?? time())) {
            return false;
        }

        return hash_equals($this->signature($attachmentId, $tenantId, $exp), $sig);
    }

    /** Relative API path (query included) for a signed download link. */
    public function path(int $attachmentId, int $tenantId, ?int $now = null): string
    {
        $signed = $this->sign($attachmentId, $tenantId, $now);

        return sprintf(
            '/api/v1/warranty/attachments/%d/file?tenant=%d&exp=%d&sig=%s',
            $attachmentId,
            $tenantId,
            $signed['exp'],
            $signed['sig']
        );
    }

    private function signature(int $attachmentId, int $tenantId, int $exp): string
    {
        $key = hash_hmac('sha256', 'attachment-url-signing', $this->secret, true);

        return hash_hmac('sha256', "{$attachmentId}|{$tenantId}|{$exp}", $key);
    }
}
