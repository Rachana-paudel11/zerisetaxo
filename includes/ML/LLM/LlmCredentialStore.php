<?php
namespace ZeriseTaxo\ML\LLM;

defined('ABSPATH') || exit;

/**
 * LlmCredentialStore
 *
 * Secure storage and resolution service for LLM provider API credentials.
 *
 * Precedence:
 *  1. wp-config.php constant `ML_WP_TAXONOMY_LLM_API_KEY` (highest priority).
 *  2. Encrypted WordPress database option `zerisetaxo_llm_api_key_enc`.
 *
 * Security Requirements:
 *  - Autoload set to 'no'.
 *  - Authenticated encryption at rest (AES-256-GCM or AES-256-CBC with HMAC-SHA256).
 *  - Cryptographic keys derived from WordPress salts (AUTH_KEY, SECURE_AUTH_KEY).
 *  - Random IV/nonce per encryption operation.
 *  - Graceful fallback on decryption failure / salt rotation (never fatals).
 *  - Never returns raw key to frontend JavaScript or REST responses.
 */
class LlmCredentialStore {

    const CONSTANT_API_KEY        = 'ZERISETAXO_LLM_API_KEY';
    const LEGACY_CONSTANT_API_KEY = 'ML_WP_TAXONOMY_LLM_API_KEY';
    const OPTION_ENCRYPTED_KEY    = 'zerisetaxo_llm_api_key_enc';
    const CIPHER_GCM              = 'aes-256-gcm';
    const CIPHER_CBC              = 'aes-256-cbc';

    /**
     * Resolves the active API key according to precedence.
     *
     * @param string $provider_id
     * @return string Raw API key or empty string if not configured.
     */
    public static function get_api_key(string $provider_id = 'gemini'): string {
        // 1. Check wp-config.php constant first (preferred ZERISETAXO, fallback ML_WP_TAXONOMY)
        if (defined(self::CONSTANT_API_KEY)) {
            $const_val = trim((string) constant(self::CONSTANT_API_KEY));
            if (!empty($const_val)) {
                return $const_val;
            }
        }
        if (defined(self::LEGACY_CONSTANT_API_KEY)) {
            $const_val = trim((string) constant(self::LEGACY_CONSTANT_API_KEY));
            if (!empty($const_val)) {
                return $const_val;
            }
        }

        // 2. Check encrypted WordPress option
        if (function_exists('get_option')) {
            $enc_payload = get_option(self::OPTION_ENCRYPTED_KEY, '');
            if (!empty($enc_payload) && is_string($enc_payload)) {
                $decrypted = self::decrypt($enc_payload);
                if (!empty($decrypted)) {
                    return $decrypted;
                }
            }
        }

        // 3. Fallback: Check server/process environment variables (OS env, Nginx/Apache fastcgi_param, or container environment)
        $env_candidates = ['ZERISETAXO_LLM_API_KEY', 'ML_WP_TAXONOMY_LLM_API_KEY', 'GEMINI_API_KEY'];
        foreach ($env_candidates as $env_name) {
            $val = getenv($env_name);
            if (false !== $val && '' !== trim((string) $val)) {
                return trim((string) $val);
            }
        }

        // SECURITY REQUIREMENT: No .env fallback in the web-accessible plugin directory.
        // Credentials must be defined via wp-config.php constants, encrypted database options,
        // or server process environment variables to prevent accidental HTTP exposure on any web server.
        return '';
    }

    /**
     * Check whether an API key is configured.
     *
     * @param string $provider_id
     * @return bool
     */
    public static function has_api_key(string $provider_id = 'gemini'): bool {
        $key = self::get_api_key($provider_id);
        return !empty($key);
    }

    /**
     * Check if the API key is provided via wp-config.php constant.
     *
     * @return bool
     */
    public static function is_key_from_constant(): bool {
        return defined(self::CONSTANT_API_KEY) && !empty(trim((string) constant(self::CONSTANT_API_KEY)));
    }

    /**
     * Encrypt and store API key in WordPress options.
     *
     * @param string $raw_key
     * @param string $provider_id
     * @return bool
     */
    public static function save_api_key(string $raw_key, string $provider_id = 'gemini'): bool {
        $clean_key = trim($raw_key);
        if (empty($clean_key)) {
            return self::delete_api_key($provider_id);
        }

        $encrypted = self::encrypt($clean_key);
        if (empty($encrypted)) {
            return false;
        }

        if (function_exists('get_option') && function_exists('add_option') && function_exists('update_option')) {
            if (get_option(self::OPTION_ENCRYPTED_KEY, null) === null) {
                return add_option(self::OPTION_ENCRYPTED_KEY, $encrypted, '', 'no');
            }
            return update_option(self::OPTION_ENCRYPTED_KEY, $encrypted, false);
        }

        $GLOBALS['zerisetaxo_options'][self::OPTION_ENCRYPTED_KEY] = $encrypted;
        return true;
    }

    /**
     * Delete stored encrypted API key.
     *
     * @param string $provider_id
     * @return bool
     */
    public static function delete_api_key(string $provider_id = 'gemini'): bool {
        if (function_exists('delete_option')) {
            return delete_option(self::OPTION_ENCRYPTED_KEY);
        }
        unset($GLOBALS['zerisetaxo_options'][self::OPTION_ENCRYPTED_KEY]);
        return true;
    }

    /**
     * Return safe masked indicator of key state for UI rendering.
     * Never returns actual decrypted key characters.
     *
     * @param string $provider_id
     * @return string
     */
    public static function get_masked_status(string $provider_id = 'gemini'): string {
        if (self::is_key_from_constant()) {
            return 'constant';
        }
        if (self::has_api_key($provider_id)) {
            return 'configured';
        }
        return 'not_configured';
    }

    /**
     * Encrypt plaintext string using WordPress salts.
     *
     * @param string $plaintext
     * @return string Base64 encoded payload: base64(cipher:iv:tag:ciphertext) or base64(cbc:iv:hmac:ciphertext)
     */
    public static function encrypt(string $plaintext): string {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $key = self::derive_encryption_key();
        $methods = openssl_get_cipher_methods();

        // Prefer AES-256-GCM (Authenticated Encryption)
        if (in_array(self::CIPHER_GCM, $methods, true)) {
            $ivlen = openssl_cipher_iv_length(self::CIPHER_GCM);
            $iv    = openssl_random_pseudo_bytes($ivlen);
            $tag   = '';
            $cipher_raw = openssl_encrypt($plaintext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher_raw === false) {
                return '';
            }
            return base64_encode('gcm::' . base64_encode($iv) . '::' . base64_encode($tag) . '::' . base64_encode($cipher_raw));
        }

        // Fallback: AES-256-CBC with HMAC-SHA256
        if (in_array(self::CIPHER_CBC, $methods, true)) {
            $ivlen = openssl_cipher_iv_length(self::CIPHER_CBC);
            $iv    = openssl_random_pseudo_bytes($ivlen);
            $cipher_raw = openssl_encrypt($plaintext, self::CIPHER_CBC, $key, OPENSSL_RAW_DATA, $iv);
            if ($cipher_raw === false) {
                return '';
            }
            $hmac = hash_hmac('sha256', $iv . $cipher_raw, $key, true);
            return base64_encode('cbc::' . base64_encode($iv) . '::' . base64_encode($hmac) . '::' . base64_encode($cipher_raw));
        }

        return '';
    }

    /**
     * Decrypt payload. Returns empty string on any decryption or authentication failure.
     *
     * @param string $payload Base64 encoded string.
     * @return string Plaintext or empty string.
     */
    public static function decrypt(string $payload): string {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return '';
        }

        $parts = explode('::', $decoded);
        if (count($parts) < 4) {
            return '';
        }

        $scheme = $parts[0];
        $key    = self::derive_encryption_key();

        if ($scheme === 'gcm') {
            $iv         = base64_decode($parts[1], true);
            $tag        = base64_decode($parts[2], true);
            $ciphertext = base64_decode($parts[3], true);
            if ($iv === false || $tag === false || $ciphertext === false) {
                return '';
            }

            $plain = openssl_decrypt($ciphertext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain !== false ? (string) $plain : '';
        }

        if ($scheme === 'cbc') {
            $iv         = base64_decode($parts[1], true);
            $hmac       = base64_decode($parts[2], true);
            $ciphertext = base64_decode($parts[3], true);
            if ($iv === false || $hmac === false || $ciphertext === false) {
                return '';
            }

            // Verify HMAC
            $expected_hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
            if (!hash_equals($expected_hmac, $hmac)) {
                return ''; // Integrity failure
            }

            $plain = openssl_decrypt($ciphertext, self::CIPHER_CBC, $key, OPENSSL_RAW_DATA, $iv);
            return $plain !== false ? (string) $plain : '';
        }

        return '';
    }

    /**
     * Derives a 256-bit encryption key from WordPress salts.
     *
     * @return string 32-byte binary key.
     */
    protected static function derive_encryption_key(): string {
        $salt = 'zerisetaxo_static_salt_fallback_2026';
        if (function_exists('wp_salt')) {
            $salt = wp_salt('auth') . wp_salt('secure_auth');
        } elseif (defined('AUTH_KEY') && defined('SECURE_AUTH_KEY')) {
            $salt = AUTH_KEY . SECURE_AUTH_KEY;
        }

        return hash('sha256', $salt, true);
    }
}
