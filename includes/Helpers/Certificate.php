<?php

namespace Famcare\ZatcaInvoicing\Helpers;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use Exception;

/**
 * Certificate helper class.
 *
 * Provides methods to manage and use X509 certificates.
 *
 * @package Famcare\ZatcaInvoicing\Helpers
 * @mixin X509
 */
class Certificate
{
    /**
     * The raw certificate content.
     *
     * @var string
     */
    protected string $rawCertificate;

    /**
     * The X509 certificate object.
     *
     * @var X509
     */
    protected X509 $x509;

    /**
     * The private key for this certificate.
     *
     * @var PrivateKey
     */
    protected PrivateKey $privateKey;

    /**
     * The secret key used for authentication.
     *
     * @var string
     */
    protected string $secretKey;

    /**
     * Constructor.
     *
     * @param string $rawCert         The raw certificate string.
     * @param string $privateKeyStr   The private key string.
     * @param string $secretKey The secret key.
     */
    public function __construct(string $rawCert, string $privateKeyStr, string $secretKey)
    {
        $this->secretKey = $secretKey;
        $this->rawCertificate = $rawCert;
        $this->x509 = new X509();
        
        // Debug certificate loading
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA Certificate Debug - Loading certificate, length: " . strlen($rawCert));
            error_log("ZATCA Certificate Debug - Certificate starts with: " . substr($rawCert, 0, 50));
        }
        
        // Try different certificate formats
        $loadResult = false;
        
        // First try as-is
        $loadResult = $this->x509->loadX509($rawCert);
        
        // If failed, try as base64 decoded (in case it's double-encoded)
        if (!$loadResult) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Debug - First load failed, trying base64 decode");
            }
            $decodedCert = base64_decode($rawCert);
            if ($decodedCert !== false) {
                $loadResult = $this->x509->loadX509($decodedCert);
            }
        }
        
        // If still failed, try adding PEM headers if missing
        if (!$loadResult && strpos($rawCert, '-----BEGIN') === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Debug - Adding PEM headers");
            }
            $pemCert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($rawCert, 64, "\n") . "-----END CERTIFICATE-----";
            $loadResult = $this->x509->loadX509($pemCert);
            if ($loadResult) {
                $this->rawCertificate = $pemCert; // Update with working format
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA Certificate Debug - Load result: " . ($loadResult ? 'SUCCESS' : 'FAILED'));
        }
        
        if ($loadResult) {
            // Test if we can get basic certificate info
            $issuerDN = $this->x509->getIssuerDN(X509::DN_STRING);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Debug - Raw Issuer DN: " . $issuerDN);
            }
            
            $currentCert = $this->x509->getCurrentCert();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Debug - getCurrentCert type: " . gettype($currentCert));
                if (is_array($currentCert)) {
                    error_log("ZATCA Certificate Debug - Certificate keys: " . implode(', ', array_keys($currentCert)));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Error - Failed to load certificate with all methods");
            }
        }
        
        // phpseclib EC defaults to sha256 and ASN.1 DER signatures for ECDSA
        // Keep defaults to maintain compatibility across versions
        $this->privateKey = EC::loadPrivateKey($privateKeyStr);
    }

    /**
     * Delegate method calls to the underlying X509 object.
     *
     * @param string $name       The method name.
     * @param array  $arguments  The method arguments.
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->x509->{$name}(...$arguments);
    }

    /**
     * Get the private key.
     *
     * @return PrivateKey
     */
    public function getPrivateKey(): PrivateKey
    {
        return $this->privateKey;
    }

    /**
     * Get the raw certificate content.
     *
     * @return string
     */
    public function getRawCertificate(): string
    {
        return $this->rawCertificate;
    }

    /**
     * Get the X509 certificate object.
     *
     * @return X509
     */
    public function getX509(): X509
    {
        return $this->x509;
    }

    /**
     * Create the authorization header using the raw certificate and secret key.
     *
     * @return string
     */
    public function getAuthHeader(): string
    {
        return 'Basic ' . base64_encode(base64_encode($this->getRawCertificate()) . ':' . $this->getSecretKey());
    }

    /**
     * Get the secret key.
     *
     * @return string|null
     */
    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    /**
     * Generate a hash of the certificate.
     * Based on working php-zatca-xml-main implementation.
     *
     * @return string
     */
    public function getCertHash(): string
    {
        // Use the exact same approach as the working php-zatca-xml-main plugin
        return base64_encode(hash('sha256', $this->rawCertificate));
    }

    /**
     * Get the formatted issuer details.
     * Based on working php-zatca-xml-main implementation.
     *
     * @return string
     */
    public function getFormattedIssuer(): string
    {
        $dnArray = explode(
            ",",
            str_replace(
                ["0.9.2342.19200300.100.1.25", "/", ", "],
                ["DC", ",", ","],
                $this->x509->getIssuerDN(X509::DN_STRING)
            )
        );

        return implode(", ", array_reverse($dnArray));
    }

    /**
     * Get Issuer in RFC2253-like format using OpenSSL parsing.
     * Falls back to getFormattedIssuer if parsing fails.
     */
    public function getIssuerRfc2253(): string
    {
        try {
            $normalized = $this->getOpenSslCompatibleCertificate();
            $certRes = $normalized !== null ? openssl_x509_read($normalized) : false;
            if ($certRes === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ZATCA Certificate Error: Failed to parse certificate");
                }
                return $this->getFormattedIssuer();
            }
            $parsed = openssl_x509_parse($certRes, false);
            if (!is_array($parsed) || empty($parsed['issuer']) || !is_array($parsed['issuer'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ZATCA Certificate Error: Failed to parse issuer");
                }
                return $this->getFormattedIssuer();
            }
            
            $issuer = $parsed['issuer'];
            
            // FIXED: For ZATCA, keep it simple like the official C# SDK example
            if (isset($issuer['CN'])) {
                $cn = is_array($issuer['CN']) ? $issuer['CN'][0] : $issuer['CN'];
                $result = 'CN=' . $cn;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ZATCA Certificate Debug - RFC2253 Issuer (simplified): " . $result);
                }
                return $result;
            }
            
            // Also check for commonName (OpenSSL sometimes uses this key)
            if (isset($issuer['commonName'])) {
                $cn = is_array($issuer['commonName']) ? $issuer['commonName'][0] : $issuer['commonName'];
                $result = 'CN=' . $cn;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ZATCA Certificate Debug - RFC2253 Issuer (from commonName): " . $result);
                }
                return $result;
            }
            
            // Fallback to full format if no CN
            $parts = [];
            foreach ($issuer as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $value) {
                        $parts[] = $k . '=' . $value;
                    }
                } else {
                    $parts[] = $k . '=' . $v;
                }
            }
            
            $result = implode(', ', $parts);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Debug - RFC2253 Issuer (full): " . $result);
            }
            return $result;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Error in getIssuerRfc2253: " . $e->getMessage());
            }
            return $this->getFormattedIssuer();
        }
    }

    /**
     * Get certificate serial number as decimal string using OpenSSL parsing when possible.
     */
    public function getSerialNumberDecimal(): string
    {
        try {
            $normalized = $this->getOpenSslCompatibleCertificate();
            $certRes = $normalized !== null ? openssl_x509_read($normalized) : false;
            if ($certRes !== false) {
                $parsed = openssl_x509_parse($certRes, false);
                if (is_array($parsed)) {
                    if (!empty($parsed['serialNumber'])) {
                        return (string)$parsed['serialNumber'];
                    }
                    if (!empty($parsed['serialNumberHex'])) {
                        // Convert hex to decimal safely
                        $hex = $parsed['serialNumberHex'];
                        // Remove possible ':' delimiters
                        $hex = str_replace(':', '', $hex);
                        // Use BCMath if available
                        if (function_exists('bcadd')) {
                            $dec = '0';
                            $len = strlen($hex);
                            for ($i = 0; $i < $len; $i++) {
                                $dec = bcmul($dec, '16');
                                $dec = bcadd($dec, (string)hexdec($hex[$i]));
                            }
                            return $dec;
                        }
                        // Fallback chunking
                        $dec = '0';
                        foreach (str_split($hex, 7) as $chunk) {
                            $dec = (string)((int)$dec * pow(16, strlen($chunk)) + hexdec($chunk));
                        }
                        return $dec;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }
        // Fallback to phpseclib parsed value
        $currentCert = $this->getCurrentCert();
        if (isset($currentCert['tbsCertificate']['serialNumber'])) {
            $serial = $currentCert['tbsCertificate']['serialNumber'];
            if (is_object($serial) && method_exists($serial, 'toString')) {
                return $serial->toString();
            }
            return (string)$serial;
        }
        return '';
    }

    /**
     * Normalize the stored certificate to a format that openssl_x509_read can parse.
     * Tries PEM first, then raw DER, and handles base64/double-base64 content and chains.
     *
     * @return string|null PEM or DER string suitable for openssl_x509_read, or null if not possible.
     */
    private function getOpenSslCompatibleCertificate(): ?string
    {
        $raw = $this->rawCertificate;

        // Case 1: Already contains PEM headers; if it's a chain, extract first cert block only
        if (strpos($raw, '-----BEGIN CERTIFICATE-----') !== false) {
            if (preg_match('/-----BEGIN CERTIFICATE-----[\s\S]*?-----END CERTIFICATE-----/m', $raw, $m)) {
                return $m[0];
            }
            return $raw;
        }

        // Case 2: Try base64 decode to DER
        $b64 = $raw;
        $der = base64_decode($b64, true);
        if ($der !== false && $der !== '') {
            // Try DER directly
            if (@openssl_x509_read($der) !== false) {
                return $der;
            }
            // Wrap as PEM and try again
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----";
            if (@openssl_x509_read($pem) !== false) {
                return $pem;
            }
        }

        // Case 3: Try double base64 (BST-like)
        $der2 = base64_decode($b64, true);
        if ($der2 !== false && $der2 !== '') {
            $der3 = base64_decode($der2, true);
            if ($der3 !== false && $der3 !== '') {
                if (@openssl_x509_read($der3) !== false) {
                    return $der3;
                }
                $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der3), 64, "\n") . "-----END CERTIFICATE-----";
                if (@openssl_x509_read($pem) !== false) {
                    return $pem;
                }
            }
        }

        // As a last resort, attempt to use phpseclib to output a PEM of the current cert
        try {
            $current = $this->x509->getCurrentCert();
            if ($current) {
                if (method_exists($this->x509, 'saveX509')) {
                    $pem = $this->x509->saveX509($current);
                    if (is_string($pem) && @openssl_x509_read($pem) !== false) {
                        return $pem;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }
    /**
     * Get the raw public key in base64 format.
     *
     * @return string
     */
    public function getRawPublicKey(): string
    {
        $key_details = $this->x509->getPublicKey();
        if (!$key_details) {
            return ''; // Return empty string if public key details are not available
        }
        return str_replace(
            ["-----BEGIN PUBLIC KEY-----\r\n", "\r\n-----END PUBLIC KEY-----", "\r\n"],
            '',
            $key_details->toString('PKCS8')
        );
    }

    /**
     * Get the current certificate data.
     *
     * @return array
     */
    public function getCurrentCert(): array
    {
        $cert = $this->x509->getCurrentCert();
        if ($cert === false || !is_array($cert)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("ZATCA Certificate Error: getCurrentCert returned " . gettype($cert));
            }
            return [];
        }
        return $cert;
    }

    /**
     * Get the certificate signature.
     *
     * Note: Removes an extra prefix byte from the signature.
     *
     * @return string
     */
    public function getCertSignature(): string
    {
        $currentCert = $this->getCurrentCert();
        if (isset($currentCert['signature'])) {
            return substr($currentCert['signature'], 1);
        }
        return '';
    }


}