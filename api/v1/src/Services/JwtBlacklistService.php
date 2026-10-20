<?php

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use App\Services\MonologHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use DomainException;
use UnexpectedValueException;

/**
 * Stores revoked JWT identifiers and checks tokens against the blacklist.
 *
 * Uses a signed token's JTI and expiry when decodable; otherwise it keys by token hash.
 */
class JwtBlacklistService
{
    private PDO $db;
    private string $jwtSecret;
    private string $tableName = 'jwt_blacklist';
    private $logger;

    public function __construct(PDO $db, string $jwtSecret)
    {
        $this->db        = $db;
        $this->jwtSecret = $jwtSecret;
        $this->logger    = MonologHandler::getInstance('jwt');
    }

    /** Add a token identifier to the blacklist until its expiry. */
    public function addToBlacklist(string $token): bool
    {
        try {
            $decoded = null;
            try {
                $decoded = $this->decodeToken($token);
            } catch (\Throwable $e) {
                // Hash fallback also supports expired or invalid tokens being revoked.
            }
            $jti = $decoded->jti ?? $this->generateJtiFromToken($token);
            $exp = $decoded->exp ?? (time() + 3600); // Default 1 hour if not set

            $stmt = $this->db->prepare("
                INSERT INTO `{$this->tableName}` (token_id, expires_at) 
                VALUES (?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE expires_at = FROM_UNIXTIME(?)
            ");

            return $stmt->execute([$jti, $exp, $exp]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to add token to blacklist', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Return whether a token is currently blacklisted. */
    public function isBlacklisted(string $token): bool
    {
        $decoded = null;
        try {
            $decoded = $this->decodeToken($token);
        } catch (\Throwable $e) {
            // Use the same hash fallback as addToBlacklist().
        }
        $jti = $decoded->jti ?? $this->generateJtiFromToken($token);

        $stmt = $this->db->prepare("
            SELECT 1 FROM `{$this->tableName}` 
            WHERE token_id = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$jti]);

        return (bool) $stmt->fetchColumn();
    }

    /** Delete expired blacklist records and return the number removed. */
    public function cleanupExpiredTokens(): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE expires_at <= NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->warning('Failed to cleanup expired tokens', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function decodeToken(string $token)
    {
        return JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
    }

    private function generateJtiFromToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
