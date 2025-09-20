<?php
/**
 * ZATCA API Manager
 *
 * Handles all communication with the ZATCA e-invoicing APIs.
 *
 * @package ZATCA_Invoicing
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ZATCA_API_Manager {

    private const API_VERSION = 'V2';

    /**
     * Get the base URI for a given environment.
     *
     * @param string $environment API environment (sandbox|simulation|production).
     * @return string The base URI.
     */
    private static function get_base_uri($environment) {
        $environments = [
            'sandbox'    => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        ];
        return $environments[$environment] ?? $environments['sandbox'];
    }

    /**
     * Request a compliance certificate from ZATCA.
     *
     * @param string $csr The Certificate Signing Request.
     * @param string $otp The One-Time Password from the Fatoora portal.
     * @param string $environment The target environment.
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public static function request_compliance_certificate($csr, $otp, $environment) {
        $url = self::get_base_uri($environment) . '/compliance';

        $headers = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Accept-Version' => self::API_VERSION,
            'OTP'            => $otp,
        ];

        $body = [
            'csr' => base64_encode($csr),
        ];

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => json_encode($body),
            'timeout' => 45, // Set a reasonable timeout.
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = 'ZATCA API Error: (' . $response_code . ') ';
            if (isset($decoded_body['message'])) {
                $error_message .= $decoded_body['message'];
            } else {
                $error_message .= 'An unknown error occurred.';
            }
            return new WP_Error('zatca_api_error', $error_message, ['status' => $response_code, 'response' => $decoded_body]);
        }

        return $decoded_body;
    }

    /**
     * Request a compliance invoice from ZATCA.
     *
     * @param array $invoice_data The invoice data.
     * @param string $environment The target environment.
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public static function request_compliance_invoice($invoice_data, $environment) {
        $url = self::get_base_uri($environment) . '/compliance/invoices';

        // Basic validation
        $required = ['invoice', 'invoiceHash', 'uuid'];
        foreach ($required as $key) {
            if (!isset($invoice_data[$key]) || empty($invoice_data[$key])) {
                return new WP_Error('zatca_invalid_payload', sprintf('Missing required field: %s', $key));
            }
        }

        // Build headers
        $headers = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Accept-Language'=> 'en',
            'Accept-Version' => self::API_VERSION,
        ];

        // Load onboarding certificate for compliance testing
        $settings = class_exists('ZATCA_Settings') ? ZATCA_Settings::instance() : null;
        $certificate_manager = class_exists('ZATCA_Certificate_Manager') ? ZATCA_Certificate_Manager::instance() : null;
        $timeout  = 45;
        $retries  = 1;
        
        if ($settings) {
            $timeout = (int) $settings->get('zatca_api_timeout', 45);
            $retries = (int) $settings->get('zatca_api_retry_attempts', 1);
        }

        if (!$certificate_manager) {
            return new WP_Error('zatca_missing_manager', __('Certificate manager not available.', 'zatca-invoicing'));
        }

        // Get onboarding certificate for compliance testing (all environments use onboarding cert for compliance)
        $certificate = $certificate_manager->get_active_certificate($environment, 'onboarding');
        if (is_wp_error($certificate)) {
            return $certificate;
        }

        $bst = $certificate['binary_security_token'];
        $secret = $certificate['secret'];

        if (empty($bst) || empty($secret)) {
            return new WP_Error('zatca_missing_credentials', __('Missing Binary Security Token or secret for compliance invoice request.', 'zatca-invoicing'));
        }

        $headers['Authorization'] = 'Basic ' . base64_encode($bst . ':' . $secret);

        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => json_encode([
                'invoice'     => $invoice_data['invoice'],
                'invoiceHash' => $invoice_data['invoiceHash'],
                'uuid'        => $invoice_data['uuid'],
            ]),
            'timeout' => max(10, $timeout),
        ];

        // Retry on transient failures (WP_Error or HTTP 5xx)
        $attempt = 0;
        $response = null;
        do {
            $attempt++;
            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                if ($attempt <= $retries) {
                    // brief sleep before retry
                    usleep(150000); // 150ms
                    continue;
                }
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 500 && $attempt <= $retries) {
                usleep(150000);
                continue;
            }
            break;
        } while ($attempt <= $retries);

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body  = json_decode($response_body, true);

        if ($response_code < 200 || $response_code >= 300) {
            // If ZATCA returned validationResults, surface them as a normal response
            if (is_array($decoded_body) && isset($decoded_body['validationResults'])) {
                $decoded_body['httpStatus'] = $response_code;
                return $decoded_body;
            }

            $error_message = 'ZATCA API Error: (' . $response_code . ') ';
            if (is_array($decoded_body) && isset($decoded_body['message'])) {
                $error_message .= $decoded_body['message'];
            } else {
                $error_message .= 'An unknown error occurred.';
            }
            return new WP_Error('zatca_api_error', $error_message, ['status' => $response_code, 'response' => $decoded_body ?: $response_body]);
        }

        return is_array($decoded_body) ? $decoded_body : ['raw' => $response_body];
    }

    /**
     * Request a production certificate from ZATCA.
     *
     * @param string $compliance_request_id The compliance request ID from the compliance certificate.
     * @param string $environment The target environment (should be 'production').
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public static function request_production_certificate($compliance_request_id, $environment) {
        // Per ZATCA docs, production CSID endpoint resides under /production/csids
        $url = self::get_base_uri($environment) . '/production/csids';

        // Build headers
        $headers = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Accept-Language'=> 'en',
            'Accept-Version' => self::API_VERSION,
        ];

        // Load onboarding certificate for authorization (production cert requests use onboarding credentials)
        $settings = class_exists('ZATCA_Settings') ? ZATCA_Settings::instance() : null;
        $certificate_manager = class_exists('ZATCA_Certificate_Manager') ? ZATCA_Certificate_Manager::instance() : null;
        $timeout  = 45;
        $retries  = 1;
        
        if ($settings) {
            $timeout = (int) $settings->get('zatca_api_timeout', 45);
            $retries = (int) $settings->get('zatca_api_retry_attempts', 1);
        }

        if (!$certificate_manager) {
            return new WP_Error('zatca_missing_manager', __('Certificate manager not available.', 'zatca-invoicing'));
        }

        // Get onboarding certificate for authorization (production cert requests use onboarding credentials)
        $certificate = $certificate_manager->get_active_certificate($environment, 'onboarding');
        if (is_wp_error($certificate)) {
            return $certificate;
        }

        $bst = $certificate['binary_security_token'];
        $secret = $certificate['secret'];

        if (empty($bst) || empty($secret)) {
            return new WP_Error('zatca_missing_credentials', __('Missing compliance Binary Security Token or secret for production certificate request.', 'zatca-invoicing'));
        }

        // Build Basic auth as base64(left:secret) where left is BST or base64(PEM)
        $left = trim($bst);
        if (strpos($left, '-----BEGIN') !== false) {
            $left = base64_encode($left);
        }
        $headers['Authorization'] = 'Basic ' . base64_encode($left . ':' . $secret);

        $body = [
            'compliance_request_id' => $compliance_request_id,
        ];

        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => json_encode($body),
            'timeout' => max(10, $timeout),
        ];

        // Retry on transient failures (WP_Error or HTTP 5xx)
        $attempt = 0;
        $response = null;
        do {
            $attempt++;
            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                if ($attempt <= $retries) {
                    // brief sleep before retry
                    usleep(150000); // 150ms
                    continue;
                }
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 500 && $attempt <= $retries) {
                usleep(150000);
                continue;
            }
            break;
        } while ($attempt <= $retries);

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body  = json_decode($response_body, true);

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = 'ZATCA API Error: (' . $response_code . ') ';
            if (is_array($decoded_body) && isset($decoded_body['message'])) {
                $error_message .= $decoded_body['message'];
            } else {
                $error_message .= 'An unknown error occurred.';
            }
            return new WP_Error('zatca_api_error', $error_message, ['status' => $response_code, 'response' => $decoded_body]);
        }

        return is_array($decoded_body) ? $decoded_body : ['raw' => $response_body];
    }

    /**
     * Submit invoice for clearance (standard, debit, credit invoices).
     * Uses production certificate credentials.
     *
     * @param array $invoice_data The invoice data (invoice, invoiceHash, uuid).
     * @param string $environment The target environment (should be 'production').
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public static function submit_invoice_clearance($invoice_data, $environment) {
        $url = self::get_base_uri($environment) . '/invoices/clearance/single';

        // Build headers
        $headers = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Accept-Language'=> 'en',
            'Accept-Version' => self::API_VERSION,
        ];

        // Load production certificate for clearance
        $settings = class_exists('ZATCA_Settings') ? ZATCA_Settings::instance() : null;
        $certificate_manager = class_exists('ZATCA_Certificate_Manager') ? ZATCA_Certificate_Manager::instance() : null;
        $timeout  = 45;
        $retries  = 1;
        
        if ($settings) {
            $timeout = (int) $settings->get('zatca_api_timeout', 45);
            $retries = (int) $settings->get('zatca_api_retry_attempts', 1);
        }

        if (!$certificate_manager) {
            return new WP_Error('zatca_missing_manager', __('Certificate manager not available.', 'zatca-invoicing'));
        }

        // Get production certificate for this environment
        $certificate = $certificate_manager->get_active_certificate($environment, 'production');
        if (is_wp_error($certificate)) {
            return $certificate;
        }

        $bst = $certificate['binary_security_token'];
        $secret = $certificate['secret'];

        if (empty($bst) || empty($secret)) {
            return new WP_Error('zatca_missing_credentials', __('Missing production Binary Security Token or secret for invoice clearance.', 'zatca-invoicing'));
        }

        // Build Basic auth as base64(left:secret) where left is BST or base64(PEM)
        $left = trim($bst);
        if (strpos($left, '-----BEGIN') !== false) {
            $left = base64_encode($left);
        }
        $headers['Authorization'] = 'Basic ' . base64_encode($left . ':' . $secret);

        // Normalize payload shape
        if (isset($invoice_data['xml']) && !isset($invoice_data['invoice'])) {
            $invoice_data['invoice'] = $invoice_data['xml'];
            unset($invoice_data['xml']);
        }
        if (isset($invoice_data['hash']) && !isset($invoice_data['invoiceHash'])) {
            $invoice_data['invoiceHash'] = $invoice_data['hash'];
            unset($invoice_data['hash']);
        }

        // Basic validation
        $required = ['invoice', 'invoiceHash', 'uuid'];
        foreach ($required as $key) {
            if (!isset($invoice_data[$key]) || empty($invoice_data[$key])) {
                return new WP_Error('zatca_invalid_payload', sprintf('Missing required field: %s', $key));
            }
        }

        // Ensure invoice is base64-encoded XML
        $invoiceRaw = $invoice_data['invoice'];
        if (is_string($invoiceRaw) && strpos($invoiceRaw, '<') !== false) {
            $invoice_data['invoice'] = base64_encode($invoiceRaw);
        }

        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => json_encode([
                'invoice'     => $invoice_data['invoice'],
                'invoiceHash' => $invoice_data['invoiceHash'],
                'uuid'        => $invoice_data['uuid'],
            ]),
            'timeout' => max(10, $timeout),
        ];

        // Retry on transient failures (WP_Error or HTTP 5xx)
        $attempt = 0;
        $response = null;
        do {
            $attempt++;
            $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
                if ($attempt <= $retries) {
                    usleep(150000); // 150ms
                    continue;
                }
            return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 500 && $attempt <= $retries) {
                usleep(150000);
                continue;
            }
            break;
        } while ($attempt <= $retries);

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body  = json_decode($response_body, true);

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = 'ZATCA API Error: (' . $response_code . ') ';
            if (is_array($decoded_body) && isset($decoded_body['message'])) {
                $error_message .= $decoded_body['message'];
            } else {
                $error_message .= 'An unknown error occurred.';
            }
            return new WP_Error('zatca_api_error', $error_message, ['status' => $response_code, 'response' => $decoded_body]);
        }

        return is_array($decoded_body) ? $decoded_body : ['raw' => $response_body];
    }

    /**
     * Submit invoice for reporting (simplified invoices).
     * Uses production certificate credentials.
     *
     * @param array $invoice_data The invoice data (invoice, invoiceHash, uuid).
     * @param string $environment The target environment (should be 'production').
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public static function submit_invoice_reporting($invoice_data, $environment) {
        $url = self::get_base_uri($environment) . '/invoices/reporting/single';

        // Build headers
        $headers = [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Accept-Language'=> 'en',
            'Accept-Version' => self::API_VERSION,
        ];

        // Load production certificate for reporting
        $settings = class_exists('ZATCA_Settings') ? ZATCA_Settings::instance() : null;
        $certificate_manager = class_exists('ZATCA_Certificate_Manager') ? ZATCA_Certificate_Manager::instance() : null;
        $timeout  = 45;
        $retries  = 1;
        
        if ($settings) {
            $timeout = (int) $settings->get('zatca_api_timeout', 45);
            $retries = (int) $settings->get('zatca_api_retry_attempts', 1);
        }

        if (!$certificate_manager) {
            return new WP_Error('zatca_missing_manager', __('Certificate manager not available.', 'zatca-invoicing'));
        }

        // Get production certificate for this environment
        $certificate = $certificate_manager->get_active_certificate($environment, 'production');
        if (is_wp_error($certificate)) {
            return $certificate;
        }

        $bst = $certificate['binary_security_token'];
        $secret = $certificate['secret'];

        if (empty($bst) || empty($secret)) {
            return new WP_Error('zatca_missing_credentials', __('Missing production Binary Security Token or secret for invoice reporting.', 'zatca-invoicing'));
        }

        // Build Basic auth as base64(left:secret) where left is BST or base64(PEM)
        $left = trim($bst);
        if (strpos($left, '-----BEGIN') !== false) {
            $left = base64_encode($left);
        }
        $headers['Authorization'] = 'Basic ' . base64_encode($left . ':' . $secret);

        // Normalize payload shape
        if (isset($invoice_data['xml']) && !isset($invoice_data['invoice'])) {
            $invoice_data['invoice'] = $invoice_data['xml'];
            unset($invoice_data['xml']);
        }
        if (isset($invoice_data['hash']) && !isset($invoice_data['invoiceHash'])) {
            $invoice_data['invoiceHash'] = $invoice_data['hash'];
            unset($invoice_data['hash']);
        }

        // Basic validation
        $required = ['invoice', 'invoiceHash', 'uuid'];
        foreach ($required as $key) {
            if (!isset($invoice_data[$key]) || empty($invoice_data[$key])) {
                return new WP_Error('zatca_invalid_payload', sprintf('Missing required field: %s', $key));
            }
        }

        // Ensure invoice is base64-encoded XML
        $invoiceRaw = $invoice_data['invoice'];
        if (is_string($invoiceRaw) && strpos($invoiceRaw, '<') !== false) {
            $invoice_data['invoice'] = base64_encode($invoiceRaw);
        }

        $args = [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => json_encode([
                'invoice'     => $invoice_data['invoice'],
                'invoiceHash' => $invoice_data['invoiceHash'],
                'uuid'        => $invoice_data['uuid'],
            ]),
            'timeout' => max(10, $timeout),
        ];

        // Retry on transient failures (WP_Error or HTTP 5xx)
        $attempt = 0;
        $response = null;
        do {
            $attempt++;
            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                if ($attempt <= $retries) {
                    usleep(150000); // 150ms
                    continue;
                }
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 500 && $attempt <= $retries) {
                usleep(150000);
                continue;
            }
            break;
        } while ($attempt <= $retries);

        if ($settings->is_debug()) {
            if (function_exists('error_log')) {
                error_log('[ZATCA Invoice Reporting] Response: ' . print_r($response, true));
            }
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body  = json_decode($response_body, true);

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = 'ZATCA API Error: (' . $response_code . ') ';
            if (is_array($decoded_body) && isset($decoded_body['message'])) {
                $error_message .= $decoded_body['message'];
            } else {
                $error_message .= 'An unknown error occurred.';
            }
            return new WP_Error('zatca_api_error', $error_message, ['status' => $response_code, 'response' => $decoded_body]);
        }

        return is_array($decoded_body) ? $decoded_body : ['raw' => $response_body];
    }
}