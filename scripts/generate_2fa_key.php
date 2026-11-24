#!/usr/bin/env php
<?php

/**
 * Generate TWO_FA_ENCRYPTION_KEY
 *
 * This script generates a secure 32-byte encryption key for 2FA secret storage.
 *
 * Usage:
 *   php scripts/generate_2fa_key.php
 *
 * Output:
 *   - Base64-encoded 32-byte key suitable for TWO_FA_ENCRYPTION_KEY environment variable
 *   - Instructions for adding to .env and Render
 *
 * Security Notes:
 *   - Key is generated using cryptographically secure random bytes (libsodium)
 *   - NEVER commit this key to version control
 *   - Store in environment variables or secrets manager
 *   - Losing the key means losing access to all encrypted 2FA secrets
 *   - Rotation requires re-encrypting all secrets with new key
 */

declare(strict_types=1);

// Check if sodium extension is available (preferred)
$useSodium = extension_loaded('sodium');

echo "================================================================================\n";
echo "                    2FA Encryption Key Generator                                \n";
echo "================================================================================\n\n";

if ($useSodium) {
    echo "✅ Using libsodium for key generation\n\n";
} else {
    echo "⚠️  libsodium not loaded, using random_bytes() instead\n\n";
}

try {
    // Generate a 32-byte random key
    if ($useSodium) {
        $key = sodium_crypto_secretbox_keygen();
    } else {
        $key = random_bytes(32); // SODIUM_CRYPTO_SECRETBOX_KEYBYTES = 32
    }
    
    // Encode to base64 for storage in environment variables
    $keyBase64 = base64_encode($key);
    
    echo "✅ Successfully generated 2FA encryption key!\n\n";
    echo "================================================================================\n";
    echo "                        COPY THIS KEY (DO NOT COMMIT)                          \n";
    echo "================================================================================\n\n";
    echo "TWO_FA_ENCRYPTION_KEY={$keyBase64}\n\n";
    echo "================================================================================\n\n";
    
    echo "📋 Setup Instructions:\n\n";
    
    echo "1️⃣  Local Development (.env file):\n";
    echo "   Add this line to your .env file:\n";
    echo "   TWO_FA_ENCRYPTION_KEY={$keyBase64}\n\n";
    
    echo "2️⃣  Render (Production):\n";
    echo "   - Go to your Render dashboard\n";
    echo "   - Navigate to your service → Environment\n";
    echo "   - Add a new environment variable:\n";
    echo "     Key: TWO_FA_ENCRYPTION_KEY\n";
    echo "     Value: {$keyBase64}\n\n";
    
    echo "3️⃣  Cloudflare Pages (if using 2FA in frontend):\n";
    echo "   - Not needed - 2FA encryption is backend only\n\n";
    
    echo "================================================================================\n";
    echo "                            ⚠️  SECURITY WARNINGS                               \n";
    echo "================================================================================\n\n";
    
    echo "⚠️  NEVER commit this key to version control (Git)\n";
    echo "⚠️  Store key securely (environment variables / secrets manager)\n";
    echo "⚠️  Losing key = losing access to ALL encrypted 2FA secrets\n";
    echo "⚠️  Key rotation requires re-encryption migration script\n";
    echo "⚠️  Use different keys for dev/staging/production environments\n\n";
    
    echo "================================================================================\n";
    echo "                              Next Steps                                        \n";
    echo "================================================================================\n\n";
    
    echo "✅ Key generated successfully\n";
    echo "🔐 Add to .env locally\n";
    echo "☁️  Add to Render environment variables\n";
    echo "🚀 Deploy backend to Render\n";
    echo "🧪 Test 2FA encryption with encrypted storage\n\n";
    
    // Clear key from memory
    if ($useSodium && function_exists('sodium_memzero')) {
        sodium_memzero($key);
    }
    
    echo "✨ Done!\n\n";
    
} catch (\Throwable $e) {
    echo "❌ Error generating key: {$e->getMessage()}\n";
    exit(1);
}
