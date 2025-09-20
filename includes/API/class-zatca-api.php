<?php
/**
 * ZATCA API Class
 *
 * Handles REST API endpoints for ZATCA functionality
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA API Class
 */
class ZATCA_API {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * WooCommerce integration
     */
    private $wc_integration;

    /**
     * Main ZATCA_API Instance.
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
     * Initialize API functionality.
     */
    private function init() {
        // Initialize WooCommerce integration
        $this->wc_integration = ZATCA_WooCommerce_Integration::instance();

        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // Orders endpoints
        register_rest_route('zatca/v1', '/orders/(?P<id>\d+)/invoice', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_invoice'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
                'force' => array(
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ));

        register_rest_route('zatca/v1', '/orders/(?P<id>\d+)/qr', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_qr_code'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
                'regenerate' => array(
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ));

        register_rest_route('zatca/v1', '/orders/(?P<id>\d+)/submit', array(
            'methods' => 'POST',
            'callback' => array($this, 'submit_invoice'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));

        // Bulk operations
        register_rest_route('zatca/v1', '/orders/bulk-generate', array(
            'methods' => 'POST',
            'callback' => array($this, 'bulk_generate_invoices'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'order_ids' => array(
                    'required' => true,
                    'validate_callback' => function($param, $request, $key) {
                        return is_array($param) && !empty($param);
                    }
                ),
            ),
        ));

        // Settings endpoints
        register_rest_route('zatca/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        register_rest_route('zatca/v1', '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        // Status endpoints
        register_rest_route('zatca/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        register_rest_route('zatca/v1', '/test-connection', array(
            'methods' => 'POST',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        // Invoice management endpoints
        register_rest_route('zatca/v1', '/invoices', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_invoices'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page' => array(
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ),
                'status' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Certificate endpoints
        register_rest_route('zatca/v1', '/certificates', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_certificates'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        register_rest_route('zatca/v1', '/certificates/(?P<id>\d+)/activate', array(
            'methods' => 'POST',
            'callback' => array($this, 'activate_certificate'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));
    }

    /**
     * Generate invoice for an order.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate_invoice($request) {
        $order_id = $request->get_param('id');
        $force = $request->get_param('force');

        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Order not found.', 'zatca-invoicing'), array('status' => 404));
        }

        // Check if invoice already exists and not forcing regeneration
        if (!$force && $this->wc_integration->has_zatca_invoice($order_id)) {
            return new WP_Error('invoice_exists', __('Invoice already exists. Use force=true to regenerate.', 'zatca-invoicing'), array('status' => 409));
        }

        // Generate invoice
        $result = $this->wc_integration->generate_zatca_invoice($order_id, 'api');

        if (is_wp_error($result)) {
            return new WP_Error('generation_failed', $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Invoice generated successfully.', 'zatca-invoicing'),
            'data' => $result,
        ));
    }

    /**
     * Get QR code for an order.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_qr_code($request) {
        $order_id = $request->get_param('id');
        $regenerate = $request->get_param('regenerate');

        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Order not found.', 'zatca-invoicing'), array('status' => 404));
        }

        // Get Phase 1 handler
        $phase1 = ZATCA_Phase1::instance();

        if ($regenerate) {
            // Delete existing QR data and regenerate
            $phase1->delete_qr_code_data($order_id);
            // Determine invoice type for the order
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('invalid_order', __('Invalid order ID.', 'zatca-invoicing'), array('status' => 400));
            }
            
            $integration = ZATCA_WooCommerce_Integration::instance();
            $invoice_type = $integration->determine_invoice_type($order);
            $qr_result = $phase1->generate_qr_code($order_id, array(), $invoice_type);
            
            if (is_wp_error($qr_result)) {
                return new WP_Error('generation_failed', $qr_result->get_error_message(), array('status' => 400));
            }
            
            $qr_data = $qr_result;
        } else {
            // Get existing QR data
            $qr_data = $phase1->get_qr_code_data($order_id);
            
            if (!$qr_data) {
                return new WP_Error('no_qr_code', __('QR code not found for this order.', 'zatca-invoicing'), array('status' => 404));
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $qr_data,
        ));
    }

    /**
     * Submit invoice to ZATCA.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function submit_invoice($request) {
        $order_id = $request->get_param('id');

        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Order not found.', 'zatca-invoicing'), array('status' => 404));
        }

        // Check if Phase 2 is enabled
        if (!$this->settings->is_phase2()) {
            return new WP_Error('phase2_disabled', __('Phase 2 is not enabled.', 'zatca-invoicing'), array('status' => 400));
        }

        // Use the same invoice type determination as WooCommerce integration
        $wc_integration = ZATCA_WooCommerce_Integration::instance();
        $invoice_type = $wc_integration->determine_invoice_type($order);

        // Get Phase 2 handler
        $phase2 = ZATCA_Phase2::instance();

        // Submit to ZATCA with environment-aware endpoint selection
        $result = $phase2->submit_invoice_to_zatca($order_id, $invoice_type, 'production');

        if (is_wp_error($result)) {
            return new WP_Error('submission_failed', $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Invoice submitted successfully.', 'zatca-invoicing'),
            'data' => $result,
        ));
    }

    /**
     * Bulk generate invoices.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function bulk_generate_invoices($request) {
        $order_ids = $request->get_param('order_ids');
        $results = array();
        $success_count = 0;
        $error_count = 0;

        foreach ($order_ids as $order_id) {
            $order_id = intval($order_id);
            
            // Check if order exists
            $order = wc_get_order($order_id);
            if (!$order) {
                $results[$order_id] = array(
                    'success' => false,
                    'error' => __('Order not found.', 'zatca-invoicing')
                );
                $error_count++;
                continue;
            }

            // Generate invoice
            $result = $this->wc_integration->generate_zatca_invoice($order_id, 'bulk_api');

            if (is_wp_error($result)) {
                $results[$order_id] = array(
                    'success' => false,
                    'error' => $result->get_error_message()
                );
                $error_count++;
            } else {
                $results[$order_id] = array(
                    'success' => true,
                    'data' => $result
                );
                $success_count++;
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => sprintf(__('Processed %d orders: %d successful, %d failed.', 'zatca-invoicing'), count($order_ids), $success_count, $error_count),
            'results' => $results,
            'summary' => array(
                'total' => count($order_ids),
                'success' => $success_count,
                'failed' => $error_count,
            ),
        ));
    }

    /**
     * Get plugin settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_settings($request) {
        $settings = $this->settings->get_settings();
        
        // Remove sensitive data
        unset($settings['zatca_api_key']);
        unset($settings['zatca_api_secret']);

        return rest_ensure_response(array(
            'success' => true,
            'data' => $settings,
        ));
    }

    /**
     * Update plugin settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings($request) {
        $new_settings = $request->get_json_params();

        if (empty($new_settings)) {
            return new WP_Error('no_settings', __('No settings data provided.', 'zatca-invoicing'), array('status' => 400));
        }

        // Validate and update settings
        foreach ($new_settings as $key => $value) {
            if (strpos($key, 'zatca_') === 0) {
                $this->settings->set($key, sanitize_text_field($value));
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Settings updated successfully.', 'zatca-invoicing'),
        ));
    }

    /**
     * Get plugin status.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_status($request) {
        global $wpdb;

        // Get invoice statistics from new table structure
        $invoice_table = $wpdb->prefix . 'zatca_invoice_submissions';
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN submission_type = 'phase1' AND status = 'completed' THEN 1 ELSE 0 END) as phase1_invoices,
                SUM(CASE WHEN submission_type IN ('onboarding', 'production') AND status IS NOT NULL AND status <> 'pending' THEN 1 ELSE 0 END) as phase2_invoices,
                SUM(CASE WHEN submission_type IN ('onboarding', 'production') AND status IN ('cleared','reported','submitted') THEN 1 ELSE 0 END) as submitted_invoices,
                SUM(CASE WHEN submission_type IN ('onboarding', 'production') AND status IN ('failed','validation_error','api_error') THEN 1 ELSE 0 END) as failed_invoices
            FROM $invoice_table",
            ARRAY_A
        );

        // Get certificate status
        $cert_manager = ZATCA_Certificate_Manager::instance();
        $active_cert = $cert_manager->get_active_certificate();

        // Check company information
        $company_validation = $this->settings->validate_company_info();

        $status = array(
            'plugin_enabled' => $this->settings->is_enabled(),
            'phase' => $this->settings->get('zatca_phase'),
            'environment' => $this->settings->get('zatca_environment'),
            'company_info_complete' => $company_validation === true,
            'certificate_active' => !is_wp_error($active_cert),
            'statistics' => $stats,
            'version' => ZATCA_INVOICING_VERSION,
            'requirements' => array(
                'php_version' => phpversion(),
                'php_openssl' => extension_loaded('openssl'),
                'php_curl' => extension_loaded('curl'),
                'php_xml' => extension_loaded('xml'),
                'woocommerce_active' => class_exists('WooCommerce'),
            ),
        );

        return rest_ensure_response(array(
            'success' => true,
            'data' => $status,
        ));
    }

    /**
     * Test ZATCA connection.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function test_connection($request) {
        if (!$this->settings->is_phase2()) {
            return new WP_Error('phase2_disabled', __('Phase 2 is not enabled.', 'zatca-invoicing'), array('status' => 400));
        }

        // Get Phase 2 handler
        $phase2 = ZATCA_Phase2::instance();

        // Test connection by calling a simple endpoint
        $test_result = $phase2->call_zatca_api('', array(), 'GET');

        if (is_wp_error($test_result)) {
            return rest_ensure_response(array(
                'success' => false,
                'message' => __('Connection test failed.', 'zatca-invoicing'),
                'error' => $test_result->get_error_message(),
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Connection test successful.', 'zatca-invoicing'),
            'response' => $test_result,
        ));
    }

    /**
     * List invoices.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function list_invoices($request) {
        global $wpdb;

        $page = $request->get_param('page');
        $per_page = min($request->get_param('per_page'), 100); // Cap at 100
        $status = $request->get_param('status');
        $offset = ($page - 1) * $per_page;

        $invoice_table = $wpdb->prefix . 'zatca_invoice_submissions';
        
        $where_clause = '1=1';
        $where_values = array();

        if ($status) {
            $where_clause .= ' AND status = %s';
            $where_values[] = $status;
        }

        $query = "SELECT * FROM $invoice_table WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;

        $invoices = $wpdb->get_results($wpdb->prepare($query, $where_values), ARRAY_A);

        // Get total count
        $count_query = "SELECT COUNT(*) FROM $invoice_table WHERE $where_clause";
        $total = $wpdb->get_var($wpdb->prepare($count_query, array_slice($where_values, 0, -2)));

        return rest_ensure_response(array(
            'success' => true,
            'data' => $invoices,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }

    /**
     * List certificates.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function list_certificates($request) {
        $cert_manager = ZATCA_Certificate_Manager::instance();
        $certificates = $cert_manager->list_certificates();

        // Remove sensitive data
        foreach ($certificates as &$cert) {
            unset($cert['certificate_data']);
            unset($cert['private_key_data']);
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $certificates,
        ));
    }

    /**
     * Activate certificate.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function activate_certificate($request) {
        $cert_id = $request->get_param('id');

        $cert_manager = ZATCA_Certificate_Manager::instance();
        $result = $cert_manager->activate_certificate($cert_id);

        if (is_wp_error($result)) {
            return new WP_Error('activation_failed', $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => __('Certificate activated successfully.', 'zatca-invoicing'),
        ));
    }

    /**
     * Check permissions for general endpoints.
     *
     * @return bool
     */
    public function check_permissions() {
        return current_user_can('manage_woocommerce');
    }

    /**
     * Check admin permissions for sensitive endpoints.
     *
     * @return bool
     */
    public function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Get API response format.
     *
     * @param bool $success
     * @param string $message
     * @param mixed $data
     * @return array
     */
    private function get_response($success, $message, $data = null) {
        $response = array(
            'success' => $success,
            'message' => $message,
        );

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $response;
    }

    /**
     * Log API requests.
     *
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @param mixed $response
     */
    private function log_api_request($endpoint, $method, $data, $response) {
        if ($this->settings->is_debug()) {
            $log_entry = array(
                'timestamp' => current_time('mysql'),
                'endpoint' => $endpoint,
                'method' => $method,
                'data' => $data,
                'response' => $response,
            );

            error_log('[ZATCA API] ' . wp_json_encode($log_entry));
        }
    }
} 