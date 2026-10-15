<?php
// config/crypto.php — symmetric encryption for document blobs
// Requires: ext-sodium, DOC_ENC_KEY (64 hex chars) defined in config/paths.php
//
// This is a leaf file. It requires NOTHING.
// DO NOT add require/include here — creates circular dependency with paths.php.

declare(strict_types=1);

if (!defined('DOC_ENC_KEY')) {
    throw new RuntimeException(
        'DOC_ENC_KEY is not defined. Include config/paths.php first.'
    );
}

if (!extension_loaded('sodium')) {
    throw new RuntimeException(
        'The sodium PHP extension is required for document encryption. '
      . 'Enable it in php.ini (extension=sodium) or install php-sodium.'
    );
}

if (!function_exists('doc_encrypt')) {
    /**
     * Encrypt plaintext. Returns nonce || ciphertext (binary).
     *
     * Output layout:
     *   [ 24 bytes nonce ][ N bytes ciphertext + 16-byte MAC ]
     */
    function doc_encrypt(string $plain): string
    {
        $key   = hex2bin(DOC_ENC_KEY);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }
}

if (!function_exists('doc_decrypt')) {
    /**
     * Decrypt a nonce||ciphertext blob. Returns plaintext, or false on failure.
     */
    function doc_decrypt(string $blob): string|false
    {
        if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return false;

        $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, hex2bin(DOC_ENC_KEY));
        return $plain === false ? false : $plain;
    }
}

if (!function_exists('doc_read_decrypt')) {
    /**
     * Read an encrypted file from disk and return plaintext, or false on failure.
     */
    function doc_read_decrypt(string $abs_path): string|false
    {
        if (!is_file($abs_path)) return false;
        $blob = file_get_contents($abs_path);
        if ($blob === false || $blob === '') return false;
        return doc_decrypt($blob);
    }
}