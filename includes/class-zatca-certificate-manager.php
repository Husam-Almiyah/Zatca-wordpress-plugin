<?php
/**
 * ZATCA Certificate Manager Class
 *
 * Handles certificate management for Phase 2 authentication and signing
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA Certificate Manager Class
 */
class ZATCA_Certificate_Manager {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Main ZATCA_Certificate_Manager Instance.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->settings = ZATCA_Settings::instance();
        $this->init();
    }

    /**
     * Initialize certificate manager.
     */
    private function init() {
        // Create secure certificates directory
        $this->ensure_certificates_directory();
        
        // Setup hooks
        add_action('zatca_certificate_cleanup', array($this, 'cleanup_expired_certificates'));
    }

    /**
     * Generate Certificate Signing Request (CSR).
     *
     * @param array $csr_data CSR information
     * @return array|WP_Error CSR and private key or error
     */
    public static function generate_csr($csr_data) {
        if (!extension_loaded('openssl')) {
            return new WP_Error('openssl_missing', __('OpenSSL extension is required for CSR generation', 'zatca-invoicing'));
        }

        // Map $csr_data to the structure used by the working package
        $dn = [
            'CN' => $csr_data['commonName'],
            'organizationName'  => $csr_data['organizationName'],
            'organizationalUnitName' => $csr_data['organizationalUnitName'],
            'C'  => strtoupper($csr_data['countryName']),
        ];

        // These fields go into the custom ZATCA extension
        $subject = [
            'SN' => $csr_data['serialNumber'], // The complex 1-xx|2-xx|3-xx serial number
            'UID' => $csr_data['uid'],
            'title' => $csr_data['invoiceType'],
            'registeredAddress' => $csr_data['registeredAddress'],
            'businessCategory' => $csr_data['businessCategory'],
        ];
        
        $settings = ZATCA_Settings::instance();
        if ($settings->is_debug()) {
            error_log('ZATCA CSR DN data: ' . print_r($dn, true));
            error_log('ZATCA CSR Subject data: ' . print_r($subject, true));
        }

        // Validate inputs (required by ZATCA)
        $validation = self::validate_csr_input($dn, $subject);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Prepare OpenSSL config file (like the package)
        $temp_config_path = tempnam(sys_get_temp_dir(), 'zatca_');
        // Pass the distinguished name array to the config builder
        $config_content = self::build_openssl_config($csr_data, $dn, $subject);
        file_put_contents($temp_config_path, $config_content);

        $openssl_conf_path = realpath($temp_config_path);

        // Create a config array specifically for private key generation.
        // Revert to the previously working curve on your stack (secp256k1)
        $pkey_config = [
            'digest_alg' => 'sha256',
            'config' => $openssl_conf_path,
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ];

        // Create a separate config array for the CSR.
        $csr_config = [
            'config' => $openssl_conf_path,
            'digest_alg' => 'sha256',
            'req_extensions' => 'v3_req',
        ];

        // Generate private key (simple, like your previous working setup)
        if ($settings->is_debug()) {
            error_log('ZATCA OpenSSL version: ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'unknown'));
            error_log('ZATCA Using OpenSSL config for pkey: ' . $openssl_conf_path);
        }
        $private_key = openssl_pkey_new($pkey_config);

        if ($settings->is_debug()) {
            error_log('ZATCA Using OpenSSL config for csr: ' . $csr_config['config']);
            error_log('ZATCA OpenSSL config content: ' . file_get_contents($temp_config_path));
        }

        if (!$private_key) {
            $errors = self::get_openssl_errors();
            unlink($temp_config_path);
            return new WP_Error('pkey_generation_failed', __('Failed to generate private key: ', 'zatca-invoicing') . $errors);
        }

        // Generate CSR
        // Add extensive logging before CSR generation
        if ($settings->is_debug()) {
            error_log('ZATCA CSR DN data: ' . print_r($dn, true));
            error_log('ZATCA CSR Config data: ' . print_r($csr_config, true));
        }

        // Generate CSR
        $csr_resource = openssl_csr_new($dn, $private_key, $csr_config);
        if (!$csr_resource) {
            $errors = self::get_openssl_errors();
            if ($settings->is_debug()) {
                error_log('ZATCA CSR Generation failed with errors: ' . $errors);
            }
            unlink($temp_config_path);
            return new WP_Error('csr_generation_failed', __('Failed to generate CSR: ', 'zatca-invoicing') . $errors);
        }
        if ($settings->is_debug()) {
            error_log('ZATCA CSR Resource: ' . print_r($csr_resource, true));
            error_log('ZATCA private_key Content: ' . print_r($private_key, true));
        }

        openssl_csr_export($csr_resource, $csr_content);
        // openssl_pkey_export($private_key, $private_key_content);
        openssl_pkey_export($private_key, $private_key_content, null, array('config' => $temp_config_path));
        if ($settings->is_debug()) {
            error_log('ZATCA Debug: Private Key Content after export (first 100 chars): ' . substr($private_key_content, 0, 100));
            error_log('ZATCA Debug: Private Key Content Length after export: ' . strlen($private_key_content));
        }

        unlink($temp_config_path);

        // Store the CSR in certificates table
        $env = $csr_data['environment'];
        $store_result = self::instance()->store_csr($csr_content, $private_key_content, $env);

        if (is_wp_error($store_result)) {
            return $store_result;
        }

        return [
            'csr_content' => $csr_content,
            'private_key' => $private_key_content,
            'public_key' => self::extract_public_key($private_key),
            'environment' => $env,
            'certificate_id' => $store_result,
        ];
    }

    private static function validate_csr_input(array $dn, array $subject)
    {
        $missing = [];
        if (empty($dn['CN'])) { $missing[] = 'commonName (CN)'; }
        if (empty($dn['organizationName'])) { $missing[] = 'organizationName (O)'; }
        if (empty($dn['organizationalUnitName'])) { $missing[] = 'organizationalUnitName (OU)'; }
        if (empty($dn['C'])) { $missing[] = 'countryName (C)'; }
        if (empty($subject['SN'])) { $missing[] = 'SN (subjectAltName dirName: SN)'; }
        if (empty($subject['UID'])) { $missing[] = 'UID (VAT)'; }
        if (empty($subject['title'])) { $missing[] = 'title (invoice type)'; }
        if (empty($subject['registeredAddress'])) { $missing[] = 'registeredAddress'; }
        if (!empty($missing)) {
            return new WP_Error('csr_missing_fields', __('Missing required CSR fields: ', 'zatca-invoicing') . implode(', ', $missing));
        }
        return true;
    }

    private static function build_openssl_config($csr_data, $dn, $subject) {
        $env = isset($csr_data['environment']) ? $csr_data['environment'] : 'sandbox';
        $envString = 'ASN1:PRINTABLESTRING:TSTZATCA-Code-Signing';
        if ($env === 'simulation') {
            $envString = 'ASN1:PRINTABLESTRING:PREZATCA-Code-Signing';
        } elseif ($env === 'production') {
            $envString = 'ASN1:PRINTABLESTRING:ZATCA-Code-Signing';
        }

        $config = "# ------------------------------------------------------------------\n";
        $config .= "# Default section for \"req\" command options -\n";
        $config .= "# ------------------------------------------------------------------\n";
        $config .= "[req]\n";
        $config .= "prompt = no\nutf8 = yes\nstring_mask = utf8only\n";
        $config .= "default_md = sha256\n";
        $config .= "distinguished_name = req_dn\n";
        $config .= "req_extensions = v3_req\n\n";
        $config .= "[req_dn]\n";
        $config .= "CN = " . ($dn['CN'] ?? '') . "\n";
        $config .= "O = " . ($dn['organizationName'] ?? '') . "\n";
        $config .= "OU = " . ($dn['organizationalUnitName'] ?? '') . "\n";
        $config .= "C = " . ($dn['C'] ?? '') . "\n";
        $config .= "\n[ v3_req ]\n1.3.6.1.4.1.311.20.2 = $envString\nsubjectAltName=dirName:subject\n\n[ subject ]";
        foreach ($subject as $k => $v) {
            if ($v !== '' && $v !== null) {
                $config .= "\n$k = $v";
            }
        }
        $config .= "\n";
        return $config;
    }

    private static function get_openssl_errors() {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }
        return implode("\n", $errors);
    }

    private static function extract_public_key($private_key) {
        $key_details = openssl_pkey_get_details($private_key);
        return $key_details['key'];
    }

    /**
     * Prepare CSR data for ZATCA Phase 2
     *
     * @param array $certificate_settings Certificate information
     * @param array $options Additional options
     * @return array Prepared CSR data
     */
    public static function prepare_zatca_csr_data($certificate_settings, $options = array()) {
        // Map certificate info to the structure expected by generate_csr (no hardcoded defaults)
        $data = array();
        $commonName = isset($certificate_settings['csr_common_name']) ? trim($certificate_settings['csr_common_name']) : '';
        $serialNumber = isset($certificate_settings['csr_serial_number']) ? trim($certificate_settings['csr_serial_number']) : '';
        $organizationIdentifier = isset($certificate_settings['csr_organization_identifier']) ? trim($certificate_settings['csr_organization_identifier']) : '';
        $organizationUnitName = isset($certificate_settings['csr_organization_unit']) ? trim($certificate_settings['csr_organization_unit']) : '';
        $organizationName = isset($certificate_settings['csr_organization_name']) ? trim($certificate_settings['csr_organization_name']) : '';
        $country = isset($certificate_settings['csr_country']) ? trim($certificate_settings['csr_country']) : '';
        $invoiceType = isset($certificate_settings['csr_invoice_type']) ? trim($certificate_settings['csr_invoice_type']) : '';
        $locationAddress = isset($certificate_settings['csr_location_address']) ? trim($certificate_settings['csr_location_address']) : '';
        $industryBusinessCategory = isset($certificate_settings['csr_industry_business_category']) ? trim($certificate_settings['csr_industry_business_category']) : '';

        $data['commonName'] = $commonName;
        $data['organizationName'] = $organizationName;
        $data['organizationalUnitName'] = $organizationUnitName;
        $data['countryName'] = $country;
        $data['serialNumber'] = $serialNumber;
        $data['uid'] = $organizationIdentifier; // VAT
        $data['invoiceType'] = $invoiceType;
        $data['registeredAddress'] = $locationAddress;
        $data['businessCategory'] = $industryBusinessCategory;
        $data['environment'] = $options['environment'] ?? 'sandbox';

        return $data;
    }

    private static function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Store CSR and private key for an environment
     *
     * @param string $csr_content CSR content
     * @param string $private_key_content Private key content
     * @param string $environment Environment (sandbox/simulation/production)
     * @return int|WP_Error Certificate ID or error
     */
    public function store_csr($csr_content, $private_key_content, $environment) {
        try {
            global $wpdb;

            // Deactivate any existing CSR records for this environment
            $this->deactivate_certificates_by_type($environment, 'csr');

            $table_name = $wpdb->prefix . 'zatca_certificates';

            $data = array(
                'certificate_name' => "ZATCA CSR ({$environment})",
                'certificate_type' => 'csr',
                'private_key_data' => trim($private_key_content),
                'csr_data' => $csr_content,
                'is_active' => 1,
                'environment' => $environment,
            );

            $result = $wpdb->insert($table_name, $data);

            if ($result === false) {
                return new WP_Error('db_insert_failed', __('Failed to store CSR in database.', 'zatca-invoicing'));
            }

            return $wpdb->insert_id;

        } catch (Exception $e) {
            return new WP_Error('csr_storage_failed', $e->getMessage());
        }
    }

    /**
     * Store certificate with type support.
     *
     * @param string $certificate_data PEM certificate data
     * @param string $private_key_data PEM private key data
     * @param array $certificate_info Certificate information
     * @param string $environment Environment (sandbox/simulation/production)
     * @param string $certificate_type Type (onboarding/production)
     * @return int|WP_Error Certificate ID or error
     */
    public function store_certificate($certificate_data, $private_key_data, $certificate_info = array(), $environment = null, $certificate_type = 'onboarding') {
        try {
            global $wpdb;

            if (!$environment) {
                $environment = $this->settings->get('zatca_environment', 'sandbox');
            }

            $table_name = $wpdb->prefix . 'zatca_certificates';

            // Check if we should update existing CSR record for onboarding certificates
            $existing_csr = null;
            if ($certificate_type === 'onboarding') {
                $existing_csr = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM $table_name WHERE environment = %s AND certificate_type = 'csr' AND is_active = 1 ORDER BY created_at DESC LIMIT 1",
                    $environment
                ));
            }

            // Deactivate other certificates of the same type in this environment
            $this->deactivate_certificates_by_type($environment, $certificate_type);
            
            $data = array(
                'certificate_name' => $certificate_info['name'] ?? "ZATCA {$certificate_type} Certificate",
                'certificate_type' => $certificate_type,
                'certificate_data' => $certificate_data,
                'private_key_data' => trim($private_key_data),
                'binary_security_token' => $certificate_info['binary_security_token'] ?? null,
                'secret' => $certificate_info['secret'] ?? null,
                'request_id' => $certificate_info['request_id'] ?? null,
                'invoice_types' => $certificate_info['invoice_types'] ?? '0100',
                'is_active' => 1,
                'environment' => $environment,
            );

            $settings = ZATCA_Settings::instance();
            if ($settings->is_debug()) {
                error_log('ZATCA Data: ' . print_r($data, true));
            }

            if ($existing_csr && $certificate_type === 'onboarding') {
                if ($settings->is_debug()) {
                    error_log('ZATCA Updating existing CSR record with certificate data');
                }
                // Update existing CSR record with certificate data
                $result = $wpdb->update(
                    $table_name,
                    $data,
                    array('id' => $existing_csr->id)
                );
                $certificate_id = $existing_csr->id;
            } else {
                if ($settings->is_debug()) {
                    error_log('ZATCA Inserting new record');
                }
                // Insert new record
                $result = $wpdb->insert($table_name, $data);
                $certificate_id = $wpdb->insert_id;
            }

            if ($result === false) {
                return new WP_Error('db_insert_failed', __('Failed to store certificate in database.', 'zatca-invoicing'));
            }

            // Update onboarding session if applicable
            if ($certificate_type === 'onboarding') {
                $this->update_onboarding_session_certificate($environment, $certificate_id, 'onboarding');
            } elseif ($certificate_type === 'production') {
                $this->update_onboarding_session_certificate($environment, $certificate_id, 'production');
            }

            return $certificate_id;

        } catch (Exception $e) {
            return new WP_Error('certificate_storage_failed', $e->getMessage());
        }
    }

    /**
     * Get active CSR for environment.
     *
     * @param string $environment Environment (sandbox/simulation/production)
     * @return array|WP_Error CSR data or error
     */
    public function get_active_csr($environment = null) {
        global $wpdb;

        if (!$environment) {
            $environment = $this->settings->get('zatca_environment', 'sandbox');
        }

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $csr = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE is_active = 1 AND environment = %s AND certificate_type = 'csr' ORDER BY created_at DESC LIMIT 1",
            $environment
        ), ARRAY_A);

        if (!$csr) {
            return new WP_Error('no_active_csr', sprintf(__('No active CSR found for %s environment.', 'zatca-invoicing'), $environment));
        }

        return array(
            'id' => $csr['id'],
            'name' => $csr['certificate_name'],
            'csr' => $csr['csr_data'],
            'private_key' => $csr['private_key_data'],
            'environment' => $csr['environment'],
        );
    }

    /**
     * Get active certificate by type.
     *
     * @param string $environment Environment (sandbox/simulation/production)
     * @param string $certificate_type Type (onboarding/production)
     * @return array|WP_Error Certificate data or error
     */
    public function get_active_certificate($environment = null, $certificate_type = 'onboarding') {
        global $wpdb;

        if (!$environment) {
            $environment = $this->settings->get('zatca_environment', 'sandbox');
        }

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $certificate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE is_active = 1 AND environment = %s AND certificate_type = %s AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1",
            $environment,
            $certificate_type
        ), ARRAY_A);

        if (!$certificate) {
            return new WP_Error('no_active_certificate', sprintf(__('No active %s certificate found for %s environment.', 'zatca-invoicing'), $certificate_type, $environment));
        }

        return array(
            'id' => $certificate['id'],
            'name' => $certificate['certificate_name'],
            'type' => $certificate['certificate_type'],
            'certificate' => $certificate['certificate_data'],
            'private_key' => $certificate['private_key_data'],
            'binary_security_token' => $certificate['binary_security_token'],
            'secret' => $certificate['secret'],
            'request_id' => $certificate['request_id'],
            'invoice_types' => $certificate['invoice_types'],
            'environment' => $certificate['environment'],
            'expires_at' => $certificate['expires_at'],
        );
    }

    /**
     * List all certificates.
     *
     * @param string $environment Environment filter
     * @return array
     */
    public function list_certificates($environment = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $where_clause = '1=1';
        $prepare_values = array();

        if ($environment) {
            $where_clause .= ' AND environment = %s';
            $prepare_values[] = $environment;
        }

        $certificates = $wpdb->get_results($wpdb->prepare(
            "SELECT id, certificate_name, certificate_type, is_active, environment, created_at, expires_at FROM $table_name WHERE $where_clause ORDER BY environment, certificate_type, created_at DESC",
            $prepare_values
        ), ARRAY_A);

        return $certificates ?: array();
    }

    /**
     * Delete certificate.
     *
     * @param int $certificate_id
     * @return bool|WP_Error
     */
    public function delete_certificate($certificate_id) {
        try {
            global $wpdb;

            $table_name = $wpdb->prefix . 'zatca_certificates';

            // Delete from database
            $result = $wpdb->delete($table_name, array('id' => $certificate_id), array('%d'));

            return $result !== false;

        } catch (Exception $e) {
            return new WP_Error('certificate_deletion_failed', $e->getMessage());
        }
    }

    /**
     * Activate certificate.
     *
     * @param int $certificate_id
     * @return bool|WP_Error
     */
    public function activate_certificate($certificate_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        // Get certificate environment
        $certificate = $wpdb->get_row($wpdb->prepare(
            "SELECT environment FROM $table_name WHERE id = %d",
            $certificate_id
        ), ARRAY_A);

        if (!$certificate) {
            return new WP_Error('certificate_not_found', __('Certificate not found.', 'zatca-invoicing'));
        }

        // Deactivate other certificates in same environment
        $this->deactivate_certificates($certificate['environment']);

        // Activate this certificate
        $result = $wpdb->update(
            $table_name,
            array('is_active' => 1),
            array('id' => $certificate_id),
            array('%d'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Load certificate content.
     *
     * @param string $cert_path
     * @return string|WP_Error
     */
    private function load_certificate_content($cert_path) {
        // Support both content and filesystem path
        if (is_string($cert_path)) {
            // If PEM content passed directly
            if (strpos($cert_path, '-----BEGIN') !== false) {
                return $cert_path;
            }
            // If base64-encoded PEM passed
            $decoded = base64_decode($cert_path, true);
            if ($decoded !== false && strpos($decoded, '-----BEGIN') !== false) {
                return $decoded;
            }
            // If it's a path on disk
            if (file_exists($cert_path)) {
                $content = file_get_contents($cert_path);
                if ($content !== false) {
                    return $content;
                }
            }
        }
        return new WP_Error('cert_load_failed', __('Failed to load certificate content.', 'zatca-invoicing'));
    }

    /**
     * Load private key content.
     *
     * @param string $key_path
     * @return string|WP_Error
     */
    private function load_private_key_content($key_path) {
        // Support both content and filesystem path
        if (is_string($key_path)) {
            if (strpos($key_path, '-----BEGIN') !== false) {
                return $key_path;
            }
            $decoded = base64_decode($key_path, true);
            if ($decoded !== false && strpos($decoded, '-----BEGIN') !== false) {
                return $decoded;
            }
            if (file_exists($key_path)) {
                $content = file_get_contents($key_path);
                if ($content !== false) {
                    return $content;
                }
            }
        }
        return new WP_Error('key_load_failed', __('Failed to load private key content.', 'zatca-invoicing'));
    }

    /**
     * Deactivate certificates for environment.
     *
     * @param string $environment
     */
    private function deactivate_certificates($environment) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $wpdb->update(
            $table_name,
            array('is_active' => 0),
            array('environment' => $environment),
            array('%d'),
            array('%s')
        );
    }

    /**
     * Deactivate certificates by type and environment.
     *
     * @param string $environment
     * @param string $certificate_type
     */
    private function deactivate_certificates_by_type($environment, $certificate_type) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $wpdb->update(
            $table_name,
            array('is_active' => 0),
            array('environment' => $environment, 'certificate_type' => $certificate_type),
            array('%d'),
            array('%s', '%s')
        );
    }

    /**
     * Update onboarding session certificate reference.
     *
     * @param string $environment
     * @param int $certificate_id
     * @param string $certificate_type
     */
    private function update_onboarding_session_certificate($environment, $certificate_id, $certificate_type) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_onboarding_sessions';
        
        // Get or create onboarding session for this environment
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE environment = %s",
            $environment
        ), ARRAY_A);

        if (!$session) {
            // Create new session
            $wpdb->insert($table_name, array(
                'environment' => $environment,
                'invoice_types' => $this->settings->get('zatca_csr_invoice_type', '0100'),
                'session_status' => 'pending'
            ));
            $session_id = $wpdb->insert_id;
        } else {
            $session_id = $session['id'];
        }

        // Update certificate reference
        $field_name = $certificate_type === 'onboarding' ? 'onboarding_cert_id' : 'production_cert_id';
        $wpdb->update(
            $table_name,
            array($field_name => $certificate_id),
            array('id' => $session_id),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Get certificates directory path.
     *
     * @return string
     */
    private function get_certificates_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/zatca-certificates';
    }

    /**
     * Ensure certificates directory exists with proper security.
     */
    private function ensure_certificates_directory() {
        $certs_dir = $this->get_certificates_directory();
        
        if (!file_exists($certs_dir)) {
            wp_mkdir_p($certs_dir);
            
            // Set restrictive permissions
            chmod($certs_dir, 0755);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($certs_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            $index_content = "<?php\n// Silence is golden.\n";
            file_put_contents($certs_dir . '/index.php', $index_content);
        }
    }

    /**
     * Cleanup expired certificates.
     */
    public function cleanup_expired_certificates() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        // Get expired certificates
        $expired_certs = $wpdb->get_results(
            "SELECT id FROM $table_name WHERE expires_at < NOW()",
            ARRAY_A
        );

        foreach ($expired_certs as $cert) {
            $wpdb->delete($table_name, array('id' => $cert['id']), array('%d'));
        }
    }



    /**
     * Export certificate (for backup purposes).
     *
     * @param int $certificate_id
     * @return array|WP_Error
     */
    public function export_certificate($certificate_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_certificates';
        
        $certificate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $certificate_id
        ), ARRAY_A);

        if (!$certificate) {
            return new WP_Error('certificate_not_found', __('Certificate not found.', 'zatca-invoicing'));
        }

        // Data in DB is stored as content (PEM or base64 PEM)
        $cert_content = $certificate['certificate_data'];
        $key_content  = $certificate['private_key_data'];

        return array(
            'name'        => $certificate['certificate_name'],
            'certificate' => $cert_content,
            'private_key' => $key_content,
            'environment' => $certificate['environment'],
            'created_at'  => $certificate['created_at'],
            'expires_at'  => $certificate['expires_at'],
        );
    }
} 