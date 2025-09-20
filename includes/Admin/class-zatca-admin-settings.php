<?php
/**
 * ZATCA Admin Settings Class
 *
 * Manages the plugin's admin settings page.
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZATCA_Admin_Settings {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Certificate Manager instance
     */
    private $certificate_manager;

    /**
     * Main ZATCA_Admin_Settings Instance.
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
        $this->certificate_manager = ZATCA_Certificate_Manager::instance();
        $this->init();
    }

    /**
     * Initialize admin settings functionality.
     */
    private function init() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_zatca_generate_csr', array($this, 'ajax_generate_csr'));
        add_action('wp_ajax_zatca_request_compliance_certificate', array($this, 'ajax_request_compliance_certificate'));
        add_action('wp_ajax_zatca_request_production_certificate', array($this, 'ajax_request_production_certificate'));
        add_action('wp_ajax_zatca_activate_certificate', array($this, 'ajax_activate_certificate'));
        add_action('wp_ajax_zatca_delete_certificate', array($this, 'ajax_delete_certificate'));
        add_action('wp_ajax_zatca_run_compliance_check', array($this, 'ajax_run_compliance_check'));
        add_action('wp_ajax_zatca_submit_invoice', array($this, 'ajax_submit_invoice'));
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        $schema = $this->settings->get_schema();

        foreach ($schema as $group_id => $group_config) {
            add_settings_section(
                'zatca_' . $group_id . '_section',
                $group_config['label'],
                array($this, 'render_section_text'),
                'zatca-e-invoicing'
            );

            foreach ($group_config['fields'] as $field_id => $field_config) {
                add_settings_field(
                    $field_id,
                    $field_config['label'],
                    array($this, 'render_field'),
                    'zatca-e-invoicing',
                    'zatca_' . $group_id . '_section',
                    array(
                        'field_id' => $field_id,
                        'field_config' => $field_config
                    )
                );
                register_setting('zatca_settings_group', $field_id);
            }
        }
    }

    /**
     * Render section text.
     */
    public function render_section_text($section) {
        // Optional: Add descriptive text for each section
    }

    /**
     * Render individual setting field.
     */
    public function render_field($args) {
        $field_id = $args['field_id'];
        $field_config = $args['field_config'];
        $value = $this->settings->get($field_id);

        $type = $field_config['type'];
        $label = $field_config['label'];
        $description = $field_config['description'] ?? '';
        $placeholder = $field_config['placeholder'] ?? '';
        $readonly = $field_config['readonly'] ?? false;

        switch ($type) {
            case 'text':
            case 'email':
            case 'url':
            case 'number':
            case 'password':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" %s/>',
                    esc_attr($type),
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    $readonly ? 'readonly' : ''
                );
                break;
            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="5" cols="50" class="large-text" %s>%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    $readonly ? 'readonly' : '',
                    esc_textarea($value)
                );
                break;
            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($field_id), esc_attr($field_id));
                foreach ($field_config['options'] as $option_value => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_value),
                        selected($value, $option_value, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                break;
        }

        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    /**
     * Settings page content.
     */
    public function settings_page_content() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('zatca_settings_group');
                do_settings_sections('zatca-e-invoicing');
                submit_button();
                ?>
            </form>

            <h2><?php _e('Certificate Management', 'zatca-invoicing'); ?></h2>
            <div id="zatca-certificate-management">
                <p><?php _e('Generate CSR and request onboarding certificate.', 'zatca-invoicing'); ?></p>
                <button type="button" class="button button-primary" id="zatca-generate-csr-btn">
                    <?php _e('Generate CSR', 'zatca-invoicing'); ?>
                </button>
                <button type="button" class="button" id="zatca-request-compliance-cert-btn">
                    <?php _e('Request Compliance Certificate', 'zatca-invoicing'); ?>
                </button>
                <div id="zatca-cert-management-result" style="margin-top: 10px;"></div>
            </div>

            <h2 style="margin-top:24px;"><?php _e('Compliance Checks for Invoice Types', 'zatca-invoicing'); ?></h2>
            <div id="zatca-compliance-check" class="card" style="padding:12px;">
                <p><?php _e('Run ZATCA compliance validation for different invoice types.', 'zatca-invoicing'); ?></p>
                <p>
                    <label for="zatca_compliance_order_id"><strong><?php _e('Order ID', 'zatca-invoicing'); ?></strong></label>
                    <input type="number" id="zatca_compliance_order_id" class="small-text" min="1" step="1" />
                    <label for="zatca_compliance_invoice_type"><strong><?php _e('Invoice Type', 'zatca-invoicing'); ?></strong></label>
                    <select id="zatca_compliance_invoice_type">
                        <option value="auto"><?php _e('Auto-detect from order', 'zatca-invoicing'); ?></option>
                        <option value="simplified"><?php _e('Simplified Invoice', 'zatca-invoicing'); ?></option>
                        <option value="standard"><?php _e('Standard Invoice', 'zatca-invoicing'); ?></option>
                        <option value="simplified-credit"><?php _e('Simplified Credit Note', 'zatca-invoicing'); ?></option>
                        <option value="simplified-debit"><?php _e('Simplified Debit Note', 'zatca-invoicing'); ?></option>
                        <option value="standard-credit"><?php _e('Standard Credit Note', 'zatca-invoicing'); ?></option>
                        <option value="standard-debit"><?php _e('Standard Debit Note', 'zatca-invoicing'); ?></option>
                    </select>
                    <button type="button" class="button" id="zatca-run-compliance-check-btn"><?php _e('Run Compliance Check', 'zatca-invoicing'); ?></button>
                </p>
                <p><em><?php printf(__('Current environment: %s', 'zatca-invoicing'), esc_html($this->settings->get('zatca_environment', 'sandbox'))); ?></em></p>
                <p><em><?php _e('To complete ZATCA onboarding, you need to run compliance checks for the invoice types.', 'zatca-invoicing'); ?></em></p>
                <div id="zatca-compliance-check-result" style="margin-top:10px;"></div>
            </div>

            <h2 style="margin-top:24px;"><?php _e('Request Production Certificate', 'zatca-invoicing'); ?></h2>
            <div id="zatca-request-production-cert" class="card" style="padding:12px;">
                <p><?php _e('Request production certificate from ZATCA (PCSID).', 'zatca-invoicing'); ?></p>
                <button type="button" class="button" id="zatca-request-production-cert-btn"><?php _e('Request Production Certificate', 'zatca-invoicing'); ?></button>
            </div>

            <h2 style="margin-top:24px;"><?php _e('Live Invoice Submission', 'zatca-invoicing'); ?></h2>
            <div id="zatca-invoice-submission" class="card" style="padding:12px;">
                <p><?php _e('Submit invoices to ZATCA for clearance/reporting. This uses production certificates and works in all environments (sandbox, simulation, production).', 'zatca-invoicing'); ?></p>
                <p>
                    <label for="zatca_submission_order_id"><strong><?php _e('Order ID', 'zatca-invoicing'); ?></strong></label>
                    <input type="number" id="zatca_submission_order_id" class="small-text" min="1" step="1" />
                    <button type="button" class="button" id="zatca-submit-invoice-btn"><?php _e('Submit Invoice', 'zatca-invoicing'); ?></button>
                </p>
                <p><em><?php _e('Invoice type is determined by your CSR Invoice Type setting and automatically detects credit/debit notes from order data.', 'zatca-invoicing'); ?></em></p>
                <p><em><?php printf(__('Current environment: %s', 'zatca-invoicing'), esc_html($this->settings->get('zatca_environment', 'sandbox'))); ?></em></p>
                <div id="zatca-submission-result" style="margin-top:10px;"></div>
            </div>

            <script type="text/javascript">
            jQuery(document).ready(function($){
                var ZATCA_DEBUG = <?php echo $this->settings->is_debug() ? 'true' : 'false'; ?>;
                $('#zatca-run-compliance-check-btn').on('click', function(){
                    var orderId = parseInt($('#zatca_compliance_order_id').val(), 10);
                    var invoiceType = $('#zatca_compliance_invoice_type').val();
                    if(!orderId){
                        $('#zatca-compliance-check-result').html('<div class="notice notice-error"><p><?php echo esc_js(__('Please enter a valid Order ID.', 'zatca-invoicing')); ?></p></div>');
                        return;
                    }
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'zatca-invoicing')); ?>');
                    $('#zatca-compliance-check-result').html('<div class="notice notice-info"><p><?php echo esc_js(__('Running compliance check...', 'zatca-invoicing')); ?></p></div>');
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zatca_run_compliance_check',
                            order_id: orderId,
                            invoice_type: invoiceType,
                            nonce: '<?php echo wp_create_nonce('zatca_admin_nonce'); ?>'
                        },
                        success: function(response){
                            if(response && response.success){
                                var data = response.data || {};
                                var html = '<div class="notice notice-success"><p>'+ (data.message || '<?php echo esc_js(__('Compliance check completed.', 'zatca-invoicing')); ?>') +'</p></div>';
                                if(data.status){ html += '<p><strong>Status:</strong> '+ data.status +'</p>'; }
                                if(ZATCA_DEBUG){
                                    if(data.errors && data.errors.length){
                                        html += '<div class="notice notice-error"><p><strong><?php echo esc_js(__('Errors', 'zatca-invoicing')); ?>:</strong></p><ul>';
                                        for(var i=0;i<data.errors.length;i++){ html += '<li>'+ data.errors[i] +'</li>'; }
                                        html += '</ul></div>';
                                    }
                                    if(data.warnings && data.warnings.length){
                                        html += '<div class="notice notice-warning"><p><strong><?php echo esc_js(__('Warnings', 'zatca-invoicing')); ?>:</strong></p><ul>';
                                        for(var j=0;j<data.warnings.length;j++){ html += '<li>'+ data.warnings[j] +'</li>'; }
                                        html += '</ul></div>';
                                    }
                                    if(data.info && data.info.length){
                                        html += '<div class="notice notice-info"><p><strong><?php echo esc_js(__('Info', 'zatca-invoicing')); ?>:</strong></p><ul>';
                                        for(var k=0;k<data.info.length;k++){ html += '<li>'+ data.info[k] +'</li>'; }
                                        html += '</ul></div>';
                                    }
                                }
                                $('#zatca-compliance-check-result').html(html);
                            } else {
                                var msg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js(__('Compliance check failed.', 'zatca-invoicing')); ?>';
                                $('#zatca-compliance-check-result').html('<div class="notice notice-error"><p>'+ msg +'</p></div>');
                            }
                        },
                        error: function(){
                            $('#zatca-compliance-check-result').html('<div class="notice notice-error"><p><?php echo esc_js(__('An error occurred.', 'zatca-invoicing')); ?></p></div>');
                        },
                        complete: function(){
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Run Compliance Check', 'zatca-invoicing')); ?>');
                        }
                    });
                });
            });
            </script>

            <h2><?php _e('Current Certificates', 'zatca-invoicing'); ?></h2>
            <table class="wp-list-table widefat fixed striped tags">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'zatca-invoicing'); ?></th>
                        <th><?php _e('Type', 'zatca-invoicing'); ?></th>
                        <th><?php _e('Environment', 'zatca-invoicing'); ?></th>
                        <th><?php _e('Active', 'zatca-invoicing'); ?></th>
                        <th><?php _e('Expires At', 'zatca-invoicing'); ?></th>
                        <th><?php _e('Actions', 'zatca-invoicing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $certificates = $this->certificate_manager->list_certificates();
                    if (!empty($certificates)) {
                        foreach ($certificates as $cert) {
                            ?>
                            <tr>
                                <td><?php echo esc_html($cert['certificate_name']); ?></td>
                                <td>
                                    <span class="cert-type cert-type-<?php echo esc_attr($cert['certificate_type'] ?? 'unknown'); ?>">
                                        <?php echo esc_html(ucfirst($cert['certificate_type'] ?? 'Unknown')); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(ucfirst($cert['environment'])); ?></td>
                                <td><?php echo $cert['is_active'] ? '✅ Yes' : '❌ No'; ?></td>
                                <td><?php echo esc_html($cert['expires_at'] ?? 'N/A'); ?></td>
                                <td>
                                    <button type="button" class="button button-small zatca-activate-cert-btn" data-cert-id="<?php echo esc_attr($cert['id']); ?>">
                                        <?php _e('Activate', 'zatca-invoicing'); ?>
                                    </button>
                                    <button type="button" class="button button-small zatca-delete-cert-btn" data-cert-id="<?php echo esc_attr($cert['id']); ?>">
                                        <?php _e('Delete', 'zatca-invoicing'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td colspan="6"><?php _e('No certificates found. Please generate a CSR and request certificates.', 'zatca-invoicing'); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>

            <style>
            .cert-type {
                padding: 3px 8px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 11px;
                text-transform: uppercase;
            }
            .cert-type-onboarding {
                background-color: #fff3e0;
                color: #e65100;
                border: 1px solid #ffcc02;
            }
            .cert-type-production {
                background-color: #e8f5e8;
                color: #2e7d32;
                border: 1px solid #4caf50;
            }
            .cert-type-unknown {
                background-color: #f5f5f5;
                color: #666;
                border: 1px solid #ccc;
            }
            </style>

        </div>
        <?php
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'zatca-admin-settings',
            ZATCA_INVOICING_PLUGIN_URL . 'assets/js/admin-settings.js',
            array('jquery'),
            ZATCA_INVOICING_VERSION,
            true
        );
        wp_localize_script(
            'zatca-admin-settings',
            'zatca_admin_ajax',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('zatca_admin_nonce')
            )
        );
        wp_enqueue_style('wp-admin');
    }

    /**
     * AJAX handler for generating CSR.
     */
    public function ajax_generate_csr() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $settings = $this->settings->get_settings();
        $certificate_settings = $this->settings->get_certificate_settings();

        // Prepare the data array for the CSR generator
        $csr_data = ZATCA_Certificate_Manager::prepare_zatca_csr_data($certificate_settings, [
            'environment' => $settings['zatca_environment'] ?? 'sandbox',
        ]);

        $result = $this->certificate_manager->generate_csr($csr_data);

        if($this->settings->is_debug()){
            error_log('ZATCA CSR Generator Data: ' . print_r($csr_data, true));
            error_log('ZATCA CSR Generator Result: ' . print_r($result, true));
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('CSR generated and saved successfully. You can now request a compliance certificate.', 'zatca-invoicing')));
        }
    }

    /**
     * AJAX handler for requesting compliance certificate.
     */
    public function ajax_request_compliance_certificate() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $environment = $this->settings->get('zatca_environment', 'sandbox');
        
        // Get active CSR for current environment
        $csr_data = $this->certificate_manager->get_active_csr($environment);
        if (is_wp_error($csr_data)) {
            wp_send_json_error(array('message' => $csr_data->get_error_message()));
        }

        $csr = $csr_data['csr'];

        $otp = '';
        if ($environment === 'production') {
            $otp = $this->settings->get_production_otp();
        } else {
            $otp = $this->settings->get_simulation_otp();
        }

        if (empty($csr)) {
            wp_send_json_error(array('message' => __('CSR not found. Please generate CSR first.', 'zatca-invoicing')));
        }
        if (empty($otp)) {
            wp_send_json_error(array('message' => __('OTP is required to request certificate. Please configure the appropriate OTP in ZATCA settings.', 'zatca-invoicing')));
        }

        $response = ZATCA_API_Manager::request_compliance_certificate($csr, $otp, $environment);

        if($this->settings->is_debug()){
            error_log('ZATCA Compliance Certificate Request: ' . print_r($csr, true));
            error_log('ZATCA Compliance Certificate OTP: ' . print_r($otp, true));
            error_log('ZATCA Compliance Certificate Response: ' . print_r($response, true));
        }

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        } else {
            if (isset($response['binarySecurityToken']) && isset($response['secret']) && isset($response['requestID'])) {
                $certificate_bst = $response['binarySecurityToken'];
                $secret = $response['secret'];
                $request_id = $response['requestID'];

                // Decode certificate if needed (API returns base64-encoded PEM)
                $certificate_pem = $certificate_bst;
                $decoded = base64_decode($certificate_bst, true);

                $certificate_pem = $decoded;

                // Store certificate and private key as onboarding certificate
                $private_key = $csr_data['private_key'];
                $cert_name = 'ZATCA Onboarding Certificate (' . ucfirst($environment) . ')';
                $cert_info = [
                    'name' => $cert_name,
                    'binary_security_token' => $certificate_bst,
                    'secret' => $secret,
                    'request_id' => $request_id,
                    'invoice_types' => $this->settings->get('zatca_csr_invoice_type', '0100')
                ];
                $store_result = $this->certificate_manager->store_certificate($certificate_pem, $private_key, $cert_info, $environment, 'onboarding');

                if (is_wp_error($store_result)) {
                    wp_send_json_error(array('message' => __('Certificate received but failed to store: ', 'zatca-invoicing') . $store_result->get_error_message()));
                }



                wp_send_json_success(array('message' => __('Compliance certificate requested and saved successfully.', 'zatca-invoicing')));
            } else {
                wp_send_json_error(array('message' => __('Invalid response from ZATCA API. Missing certificate data.', 'zatca-invoicing')));
            }
        }
    }

    /**
     * AJAX handler for requesting production certificate.
     */
    public function ajax_request_production_certificate() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $environment = $this->settings->get('zatca_environment', 'sandbox');

        // Check if we have compliance credentials (BST and secret) from database
        $compliance_cert = $this->certificate_manager->get_active_certificate($environment, 'onboarding');
        if (is_wp_error($compliance_cert)) {
            wp_send_json_error(array('message' => __('Missing compliance certificate. Please ensure you have a valid compliance certificate.', 'zatca-invoicing')));
        }

        if (empty($compliance_cert['binary_security_token']) || empty($compliance_cert['secret'])) {
            wp_send_json_error(array('message' => __('Missing compliance credentials. Please ensure you have a valid compliance certificate.', 'zatca-invoicing')));
        }

        // Get compliance request ID from the certificate
        $compliance_request_id = $compliance_cert['request_id'];
        if (empty($compliance_request_id)) {
            wp_send_json_error(array('message' => __('Compliance request ID not found. Please request a compliance certificate first.', 'zatca-invoicing')));
        }

        $response = ZATCA_API_Manager::request_production_certificate($compliance_request_id, $environment);

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        } else {
            if (isset($response['binarySecurityToken']) && isset($response['secret'])) {
                $certificate_data = $response['binarySecurityToken'];
                $secret = $response['secret'];

                $certificate_pem = base64_decode($certificate_data, true);

                // Store certificate and private key as production certificate
                $private_key = $compliance_cert['private_key'];
                $cert_name = 'ZATCA Production Certificate (' . ucfirst($environment) . ')';
                $cert_info = [
                    'name' => $cert_name,
                    'binary_security_token' => $certificate_data,
                    'secret' => $secret,
                    'invoice_types' => $this->settings->get('zatca_csr_invoice_type', '0100')
                ];
                $store_result = $this->certificate_manager->store_certificate($certificate_pem, $private_key, $cert_info, $environment, 'production');

                if (is_wp_error($store_result)) {
                    wp_send_json_error(array('message' => __('Certificate received but failed to store: ', 'zatca-invoicing') . $store_result->get_error_message()));
                }

                wp_send_json_success(array('message' => __('Production certificate requested and saved successfully.', 'zatca-invoicing')));
            } else {
                wp_send_json_error(array('message' => __('Invalid response from ZATCA API. Missing certificate data.', 'zatca-invoicing')));
            }
        }
    }

    /**
     * AJAX handler: Run compliance validation for a specific order
     */
    public function ajax_run_compliance_check() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $invoice_type_param = isset($_POST['invoice_type']) ? sanitize_text_field($_POST['invoice_type']) : 'auto';

        if ($order_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid Order ID.', 'zatca-invoicing')));
        }

        $environment = $this->settings->get('zatca_environment', 'sandbox');

        $phase2 = ZATCA_Phase2::instance();

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'zatca-invoicing')));
        }

        // Determine invoice type
        if ($invoice_type_param === 'auto') {
            $wc_integration = ZATCA_WooCommerce_Integration::instance();
            $invoice_type = $wc_integration->determine_invoice_type($order);
        } else {
            $invoice_type = $invoice_type_param;
        }

        $gen = $phase2->generate_xml_invoice($order_id, $invoice_type, ['certificate_type' => 'onboarding']);
        if (is_wp_error($gen)) {
            wp_send_json_error(array('message' => $gen->get_error_message()));
        }
        $xml_data = $phase2->get_xml_invoice_data($order_id, 'onboarding');
        if (!$xml_data) {
            wp_send_json_error(array('message' => __('Unable to prepare XML invoice for compliance check.', 'zatca-invoicing')));
        }

        // Build compliance payload
        $payload = array(
            'invoice'     => base64_encode($xml_data['xml']),
            'invoiceHash' => $xml_data['hash'],
            'uuid'        => $xml_data['uuid'],
        );

        $response = ZATCA_API_Manager::request_compliance_invoice($payload, $environment);
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        // Extract validation status/errors/warnings and info (if debug)
        $status = 'validated';
        $errors = array();
        $warnings = array();
        $info = array();
        if (isset($response['validationResults'])) {
            $vr = $response['validationResults'];
            if (!empty($vr['status'])) {
                $status = strtolower($vr['status']);
            }
            // Some gateways respond with errorMessages/warningMessages/infoMessages
            $errorList = array();
            $warningList = array();
            $infoList = array();
            if (!empty($vr['errors'])) { $errorList = $vr['errors']; }
            if (!empty($vr['errorMessages'])) { $errorList = array_merge($errorList, $vr['errorMessages']); }
            if (!empty($vr['warnings'])) { $warningList = $vr['warnings']; }
            if (!empty($vr['warningMessages'])) { $warningList = array_merge($warningList, $vr['warningMessages']); }
            if (!empty($vr['infoMessages'])) { $infoList = array_merge($infoList, $vr['infoMessages']); }

            if (!empty($errorList) && is_array($errorList)) {
                foreach ($errorList as $e) {
                    $errors[] = (isset($e['message']) ? $e['message'] : __('Unknown error', 'zatca-invoicing')) . (isset($e['code']) ? ' ('.$e['code'].')' : '');
                }
            }
            if (!empty($warningList) && is_array($warningList)) {
                foreach ($warningList as $w) {
                    $warnings[] = (isset($w['message']) ? $w['message'] : __('Warning', 'zatca-invoicing')) . (isset($w['code']) ? ' ('.$w['code'].')' : '');
                }
            }
            if (!empty($infoList) && is_array($infoList)) {
                foreach ($infoList as $im) {
                    $info[] = (isset($im['message']) ? $im['message'] : __('Info', 'zatca-invoicing')) . (isset($im['code']) ? ' ('.$im['code'].')' : '');
                }
            }
        }

        // Persist validation for reference
        update_post_meta($order_id, '_zatca_validation_results', wp_json_encode($response['validationResults'] ?? array()));

        wp_send_json_success(array(
            'message'  => __('Compliance check completed.', 'zatca-invoicing'),
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
            'info'     => $info,
            'raw'      => $response,
        ));
    }

    /**
     * AJAX handler for activating a certificate.
     */
    public function ajax_activate_certificate() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $cert_id = isset($_POST['cert_id']) ? intval($_POST['cert_id']) : 0;

        if (!$cert_id) {
            wp_send_json_error(array('message' => __('Invalid certificate ID.', 'zatca-invoicing')));
        }

        $result = $this->certificate_manager->activate_certificate($cert_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else if (!$result) {
            wp_send_json_error(array('message' => __('Failed to activate certificate.', 'zatca-invoicing')));
        } else {
            wp_send_json_success(array('message' => __('Certificate activated successfully.', 'zatca-invoicing')));
        }
    }

    /**
     * AJAX handler for deleting a certificate.
     */
    public function ajax_delete_certificate() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $cert_id = isset($_POST['cert_id']) ? intval($_POST['cert_id']) : 0;

        if (!$cert_id) {
            wp_send_json_error(array('message' => __('Invalid certificate ID.', 'zatca-invoicing')));
        }

        $result = $this->certificate_manager->delete_certificate($cert_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else if (!$result) {
            wp_send_json_error(array('message' => __('Failed to delete certificate.', 'zatca-invoicing')));
        } else {
            wp_send_json_success(array('message' => __('Certificate deleted successfully.', 'zatca-invoicing')));
        }
    }

    /**
     * AJAX handler: Submit invoice to ZATCA for clearance/reporting
     */
    public function ajax_submit_invoice() {
        check_ajax_referer('zatca_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if ($order_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid Order ID.', 'zatca-invoicing')));
        }

        // Auto-detect invoice type based on order details
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'zatca-invoicing')));
        }

        // Use the same invoice type determination as WooCommerce integration
        $wc_integration = ZATCA_WooCommerce_Integration::instance();
        $invoice_type = $wc_integration->determine_invoice_type($order);

        $environment = $this->settings->get('zatca_environment', 'sandbox');
        
        // Live invoice submission is available in all environments

        // Check if we have production certificate for this environment
        $production_cert = $this->certificate_manager->get_active_certificate($environment, 'production');
        if (is_wp_error($production_cert)) {
            wp_send_json_error(array('message' => sprintf(__('Missing production certificate for %s environment. Please ensure you have completed the onboarding process.', 'zatca-invoicing'), $environment)));
        }

        // Get XML invoice data first or generate if missing
        $phase2 = ZATCA_Phase2::instance();
        $xml_data = $phase2->get_xml_invoice_data($order_id, 'production');

        if (!$xml_data) {
            // Try to generate using the specified invoice type
            $gen = $phase2->generate_xml_invoice($order_id, $invoice_type, ['certificate_type' => 'production']);
            if (is_wp_error($gen)) {
                wp_send_json_error(array('message' => $gen->get_error_message()));
            }
            $xml_data = $phase2->get_xml_invoice_data($order_id, 'production');
            if (!$xml_data) {
                wp_send_json_error(array('message' => __('Unable to prepare XML invoice for submission.', 'zatca-invoicing')));
            }
        }

        // Submit invoice to ZATCA
        $result = $phase2->submit_invoice_to_zatca($order_id, $invoice_type, 'production');
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Extract submission status and details
        $status = 'submitted';
        $irn = null;
        $pih = null;
        $qr_code = null;
        $errors = array();
        
        if (isset($result['clearanceStatus']) && $result['clearanceStatus'] === 'CLEARED') {
            $status = 'cleared';
            $irn = $result['invoiceHash'] ?? null;
            $pih = $result['previousInvoiceHash'] ?? null;
            $qr_code = $result['qrCode'] ?? null;
        } elseif (isset($result['reportingStatus']) && $result['reportingStatus'] === 'REPORTED') {
            $status = 'reported';
            $irn = $result['invoiceHash'] ?? null;
            $pih = $result['previousInvoiceHash'] ?? null;
            $qr_code = $result['qrCode'] ?? null;
        } elseif (isset($result['validationResults']['status']) && $result['validationResults']['status'] === 'ERROR') {
            $status = 'validation_error';
            if (isset($result['validationResults']['errors'])) {
                foreach ($result['validationResults']['errors'] as $error) {
                    $errors[] = $error['message'] . ' (Code: ' . $error['code'] . ')';
                }
            }
        }

        wp_send_json_success(array(
            'message'  => __('Invoice submitted successfully.', 'zatca-invoicing'),
            'status'   => $status,
            'irn'      => $irn,
            'pih'      => $pih,
            'qr_code'  => $qr_code,
            'errors'   => $errors,
            'raw'      => $result,
        ));
    }
}