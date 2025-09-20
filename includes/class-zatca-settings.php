<?php
/**
 * Enhanced ZATCA Settings Class
 *
 * Enhanced settings management with flexible configuration and validation
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced ZATCA Settings Class
 */
class ZATCA_Settings {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings configuration schema
     */
    private $settings_schema = array();

    /**
     * Settings data
     */
    private $settings = array();

    /**
     * Main ZATCA_Settings Instance.
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
        $this->init_schema();
        $this->init();
    }

    /**
     * Initialize settings schema.
     */
    private function init_schema() {
        $this->settings_schema = array(
            // General Settings Group
            'general' => array(
                'label' => __('General Settings', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_enabled' => array(
                        'type' => 'select',
                        'label' => __('Enable ZATCA Integration', 'zatca-invoicing'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Enabled', 'zatca-invoicing'),
                            'no' => __('Disabled', 'zatca-invoicing')
                        ),
                        'required' => true
                    ),
                    'zatca_phase' => array(
                        'type' => 'select',
                        'label' => __('ZATCA Phase', 'zatca-invoicing'),
                        'default' => 'phase1',
                        'options' => array(
                            'phase1' => __('Phase 1 (QR Code Only)', 'zatca-invoicing'),
                            'phase2' => __('Phase 2 (XML Invoices + API)', 'zatca-invoicing')
                        ),
                        'required' => true
                    ),
                    'zatca_environment' => array(
                        'type' => 'select',
                        'label' => __('Environment', 'zatca-invoicing'),
                        'default' => 'sandbox',
                        'options' => array(
                            'sandbox' => __('Sandbox (Testing)', 'zatca-invoicing'),
                            'simulation' => __('Simulation', 'zatca-invoicing'),
                            'production' => __('Production', 'zatca-invoicing')
                        ),
                        'required' => true
                    ),

                    'zatca_debug' => array(
                        'type' => 'select',
                        'label' => __('Debug Mode', 'zatca-invoicing'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Enabled', 'zatca-invoicing'),
                            'no' => __('Disabled', 'zatca-invoicing')
                        )
                    ),
                )
            ),

            // Company Information Group
            'company' => array(
                'label' => __('Company Information', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_seller_name' => array(
                        'type' => 'text',
                        'label' => __('Company Name', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|max:255'
                    ),
                    'zatca_vat_number' => array(
                        'type' => 'text',
                        'label' => __('VAT Registration Number', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|vat_number',
                        'placeholder' => '3XXXXXXXXXX'
                    ),
                    'zatca_business_category' => array(
                        'type' => 'text',
                        'label' => __('Business Category', 'zatca-invoicing'),
                        'default' => 'Supply activities to support businesses',
                        'validation' => 'max:255'
                    ),
                    'zatca_building_number' => array(
                        'type' => 'text',
                        'label' => __('Building Number', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|max:10'
                    ),
                    'zatca_street_name' => array(
                        'type' => 'text',
                        'label' => __('Street Name', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|max:255'
                    ),
                    'zatca_district' => array(
                        'type' => 'text',
                        'label' => __('District', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|max:255'
                    ),
                    'zatca_city' => array(
                        'type' => 'text',
                        'label' => __('City', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|max:255'
                    ),
                    'zatca_postal_code' => array(
                        'type' => 'text',
                        'label' => __('Postal Code', 'zatca-invoicing'),
                        'default' => '',
                        'required' => true,
                        'validation' => 'required|postal_code'
                    ),
                    'zatca_country_code' => array(
                        'type' => 'select',
                        'label' => __('Country', 'zatca-invoicing'),
                        'default' => 'SA',
                        'options' => array(
                            'SA' => __('Saudi Arabia', 'zatca-invoicing')
                        ),
                        'required' => true
                    ),
                )
            ),

            // Invoice Settings Group
            'invoice' => array(
                'label' => __('Invoice Settings', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_auto_generate' => array(
                        'type' => 'select',
                        'label' => __('Auto Generate Invoices', 'zatca-invoicing'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes', 'zatca-invoicing'),
                            'no' => __('No', 'zatca-invoicing')
                        ),
                        'description' => __('Automatically generate invoices when orders are processed.', 'zatca-invoicing')
                    ),
                    'zatca_auto_submit' => array(
                        'type' => 'select',
                        'label' => __('Auto Submit Invoices to ZATCA', 'zatca-invoicing'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Yes', 'zatca-invoicing'),
                            'no' => __('No', 'zatca-invoicing')
                        ),
                        'description' => __('Automatically submit generated XML invoices to ZATCA API.', 'zatca-invoicing')
                    ),
                    'zatca_invoice_counter_prefix' => array(
                        'type' => 'text',
                        'label' => __('Invoice Counter Prefix', 'zatca-invoicing'),
                        'default' => 'INV',
                        'validation' => 'max:10'
                    ),
                    'zatca_invoice_counter_start' => array(
                        'type' => 'number',
                        'label' => __('Invoice Counter Start', 'zatca-invoicing'),
                        'default' => '1',
                        'validation' => 'numeric|min:1'
                    ),
                    'zatca_qr_size' => array(
                        'type' => 'number',
                        'label' => __('QR Code Size (pixels)', 'zatca-invoicing'),
                        'default' => '200',
                        'validation' => 'numeric|value_between:100,500'
                    ),
                    'zatca_qr_position' => array(
                        'type' => 'select',
                        'label' => __('QR Code Position', 'zatca-invoicing'),
                        'default' => 'after_order_details',
                        'options' => array(
                            'after_order_details' => __('After Order Details', 'zatca-invoicing'),
                            'before_order_details' => __('Before Order Details', 'zatca-invoicing'),
                            'in_footer' => __('In Footer', 'zatca-invoicing'),
                            'custom' => __('Custom Hook', 'zatca-invoicing')
                        )
                    ),
                )
            ),

            // API Settings Group
            'api' => array(
                'label' => __('API Settings', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_api_base_url_sandbox' => array(
                        'type' => 'url',
                        'label' => __('Sandbox API URL', 'zatca-invoicing'),
                        'default' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
                        'validation' => 'url'
                    ),
                    'zatca_api_base_url_simulation' => array(
                        'type' => 'url',
                        'label' => __('Simulation API URL', 'zatca-invoicing'),
                        'default' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
                        'validation' => 'url'
                    ),
                    'zatca_api_base_url_production' => array(
                        'type' => 'url',
                        'label' => __('Production API URL', 'zatca-invoicing'),
                        'default' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
                        'validation' => 'url'
                    ),
                    'zatca_api_timeout' => array(
                        'type' => 'number',
                        'label' => __('API Timeout (seconds)', 'zatca-invoicing'),
                        'default' => '30',
                        'validation' => 'numeric|value_between:30,300'
                    ),
                    'zatca_api_retry_attempts' => array(
                        'type' => 'number',
                        'label' => __('API Retry Attempts', 'zatca-invoicing'),
                        'default' => '3',
                        'validation' => 'numeric|value_between:1,10'
                    ),
                    'zatca_simulation_otp' => array(
                        'type' => 'password',
                        'label' => __('Simulation OTP', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:10',
                        'description' => __('Required for simulation environment authentication.', 'zatca-invoicing')
                    ),
                    'zatca_production_otp' => array(
                        'type' => 'password',
                        'label' => __('Production OTP', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:10',
                        'description' => __('Required for production environment authentication when requesting compliance or production CSIDs.', 'zatca-invoicing')
                    ),
                    'zatca_simulation_accept_version' => array(
                        'type' => 'text',
                        'label' => __('Simulation Accept-Version', 'zatca-invoicing'),
                        'default' => 'V2',
                        'description' => __('API version header for simulation environment.', 'zatca-invoicing')
                    ),
                )
            ),

            // Certificate Settings Group
            'certificate' => array(
                'label' => __('Certificate Settings', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_csr_common_name' => array(
                        'type' => 'text',
                        'label' => __('CSR Common Name', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_serial_number' => array(
                        'type' => 'text',
                        'label' => __('CSR Serial Number', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_organization_identifier' => array(
                        'type' => 'text',
                        'label' => __('CSR Organization Identifier', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_organization_unit' => array(
                        'type' => 'text',
                        'label' => __('CSR Organization Unit', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_organization_name' => array(
                        'type' => 'text',
                        'label' => __('CSR Organization Name', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_country' => array(
                        'type' => 'select',
                        'label' => __('CSR Country', 'zatca-invoicing'),
                        'default' => 'SA',
                        'options' => array(
                            'SA' => __('Saudi Arabia', 'zatca-invoicing')
                        )
                    ),
                    'zatca_csr_invoice_type' => array(
                        'type' => 'select',
                        'label' => __('Supported Invoice Types', 'zatca-invoicing'),
                        'default' => '0100',
                        'options' => array(
                            '0100' => __('Simplified Only (B2C) - 02xxxxx', 'zatca-invoicing'),
                            '1000' => __('Standard Only (B2B) - 01xxxxx', 'zatca-invoicing'),
                            '1100' => __('Both Standard & Simplified - Auto-detect based on customer VAT', 'zatca-invoicing'),
                        ),
                        'required' => true,
                        'description' => __('This determines which invoice types your certificate can generate. Auto-detection will use Standard for customers with VAT numbers, Simplified for others.', 'zatca-invoicing')
                    ),
                    'zatca_csr_location_address' => array(
                        'type' => 'text',
                        'label' => __('CSR Location Address', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),
                    'zatca_csr_industry_business_category' => array(
                        'type' => 'text',
                        'label' => __('CSR Industry Business Category', 'zatca-invoicing'),
                        'default' => '',
                        'validation' => 'max:255'
                    ),


                )
            ),

            // Advanced Settings Group
            'advanced' => array(
                'label' => __('Advanced Settings', 'zatca-invoicing'),
                'fields' => array(
                    'zatca_email_notifications' => array(
                        'type' => 'select',
                        'label' => __('Email Notifications', 'zatca-invoicing'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Enabled', 'zatca-invoicing'),
                            'no' => __('Disabled', 'zatca-invoicing')
                        )
                    ),
                    'zatca_notification_email' => array(
                        'type' => 'email',
                        'label' => __('Notification Email', 'zatca-invoicing'),
                        'default' => get_option('admin_email'),
                        'validation' => 'email'
                    ),
                    'zatca_sync_frequency' => array(
                        'type' => 'select',
                        'label' => __('Sync Frequency', 'zatca-invoicing'),
                        'default' => 'hourly',
                        'options' => array(
                            'hourly' => __('Hourly', 'zatca-invoicing'),
                            'daily' => __('Daily', 'zatca-invoicing'),
                            'weekly' => __('Weekly', 'zatca-invoicing')
                        )
                    ),
                )
            )
        );
    }

    /**
     * Initialize settings.
     */
    private function init() {
        $this->load_settings();
        add_action('init', array($this, 'register_settings'));
    }

    /**
     * Load all settings.
     */
    private function load_settings() {
        foreach ($this->settings_schema as $group => $group_config) {
            foreach ($group_config['fields'] as $field_name => $field_config) {
                $this->settings[$field_name] = get_option($field_name, $field_config['default']);
            }
        }
    }

    /**
     * Register settings for WordPress options API.
     */
    public function register_settings() {
        foreach ($this->settings_schema as $group => $group_config) {
            $group_name = 'zatca_' . $group . '_settings';
            register_setting($group_name, $group_name);
            
            foreach ($group_config['fields'] as $field_name => $field_config) {
                register_setting($group_name, $field_name);
            }
        }
    }

    /**
     * Get a setting value.
     */
    public function get($key, $default = null) {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    /**
     * Set a setting value.
     */
    public function set($key, $value) {
        // Validate before setting
        $validation_result = $this->validate_field($key, $value);
        if (is_wp_error($validation_result)) {
            if ($this->is_debug()) {
                error_log('ZATCA Debug: Validation failed for ' . $key . ': ' . $validation_result->get_error_message());
            }
            return $validation_result;
        }

        $this->settings[$key] = $value;
        update_option($key, $value);

        return true;
    }

    /**
     * Get settings schema.
     */
    public function get_schema() {
        return $this->settings_schema;
    }

    /**
     * Get schema for specific group.
     */
    public function get_group_schema($group) {
        return isset($this->settings_schema[$group]) ? $this->settings_schema[$group] : false;
    }

    /**
     * Get all settings as an array.
     * @return array
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get field schema.
     */
    /**
     * Get field config (alias for get_field_schema).
     */
    public function get_field_config($field_name) {
        return $this->get_field_schema($field_name);
    }

    /**
     * Get field schema.
     */
    public function get_field_schema($field_name) {
        foreach ($this->settings_schema as $group) {
            if (isset($group['fields'][$field_name])) {
                return $group['fields'][$field_name];
            }
        }
        return false;
    }

    /**
     * Get all settings for a specific group.
     * @param string $group_name The name of the group to retrieve.
     * @return array An array of settings for the specified group.
     */
    public function get_group($group_name) {
        $group_settings = [];
        $schema = $this->get_group_schema($group_name);
        if ($schema && isset($schema['fields'])) {
            foreach (array_keys($schema['fields']) as $field_name) {
                $group_settings[$field_name] = $this->get($field_name);
            }
        }
        return $group_settings;
    }

    /**
     * Validate a field value.
     */
    public function validate_field($field_name, $value) {
        $field_schema = $this->get_field_schema($field_name);
        if (!$field_schema) {
            return new WP_Error('invalid_field', __('Invalid field name.', 'zatca-invoicing'));
        }

        // Check if required field is empty
        if (!empty($field_schema['required']) && empty($value)) {
            return new WP_Error('required_field', sprintf(__('Field %s is required.', 'zatca-invoicing'), $field_schema['label']));
        }

        // Apply validation rules
        if (!empty($field_schema['validation'])) {
            return $this->apply_validation_rules($value, $field_schema['validation'], $field_schema['label']);
        }

        return true;
    }

    /**
     * Apply validation rules.
     */
    private function apply_validation_rules($value, $rules, $field_label) {
        $rules_array = explode('|', $rules);
        
        foreach ($rules_array as $rule) {
            $rule_parts = explode(':', $rule);
            $rule_name = $rule_parts[0];
            $rule_param = isset($rule_parts[1]) ? $rule_parts[1] : null;

            switch ($rule_name) {
                case 'required':
                    if (empty($value)) {
                        return new WP_Error('validation_failed', sprintf(__('%s is required.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'email':
                    if (!empty($value) && !is_email($value)) {
                        return new WP_Error('validation_failed', sprintf(__('%s must be a valid email address.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'url':
                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                        return new WP_Error('validation_failed', sprintf(__('%s must be a valid URL.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'numeric':
                    if (!empty($value) && !is_numeric($value)) {
                        return new WP_Error('validation_failed', sprintf(__('%s must be a number.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'min':
                    if (!empty($value)) {
                        $length = is_numeric($value) ? strlen((string) $value) : strlen($value);
                        if ($length < $rule_param) {
                            return new WP_Error(
                                'validation_failed',
                                sprintf(__('%s must be at least %s characters/digits.', 'zatca-invoicing'), $field_label, $rule_param)
                            );
                        }
                    }
                    break;

                case 'max':
                    if (!empty($value)) {
                        $length = is_numeric($value) ? strlen((string) $value) : strlen($value);
                        if ($length > $rule_param) {
                            return new WP_Error(
                                'validation_failed',
                                sprintf(__('%s must not exceed %s characters/digits.', 'zatca-invoicing'), $field_label, $rule_param)
                            );
                        }
                    }
                    break;

                case 'vat_number':
                    if (!empty($value) && !$this->validate_vat_number($value)) {
                        return new WP_Error('validation_failed', sprintf(__('%s must be a valid Saudi VAT number.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'postal_code':
                    if (!empty($value) && !preg_match('/^[0-9]{5}$/', $value)) {
                        return new WP_Error('validation_failed', sprintf(__('%s must be a 5-digit postal code.', 'zatca-invoicing'), $field_label));
                    }
                    break;

                case 'value_between':
                    if (!empty($value) && (!is_numeric($value) || $value < explode(',', $rule_param)[0] || $value > explode(',', $rule_param)[1])) {
                        return new WP_Error(
                            'validation_failed',
                            sprintf(__('%s must be between %s and %s.', 'zatca-invoicing'), $field_label, explode(',', $rule_param)[0], explode(',', $rule_param)[1])
                        );
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Validate VAT number format.
     */
    public function validate_vat_number($vat_number) {
        // Saudi VAT number should be 15 digits and start with 3
        $vat_number = preg_replace('/[^0-9]/', '', $vat_number);
        return preg_match('/^3[0-9]{14}$/', $vat_number);
    }

    /**
     * Get API base URL based on environment.
     */
    public function get_api_base_url() {
        $environment = $this->get('zatca_environment');
        
        switch ($environment) {
            case 'sandbox':
                return $this->get('zatca_api_base_url_sandbox');
            case 'simulation':
                return $this->get('zatca_api_base_url_simulation');
            case 'production':
                return $this->get('zatca_api_base_url_production');
            default:
                return $this->get('zatca_api_base_url_sandbox');
        }
    }

    /**
     * Check if ZATCA is enabled.
     */
    public function is_enabled() {
        return $this->get('zatca_enabled') === 'yes';
    }

    /**
     * Check if we're in Phase 2 mode.
     */
    public function is_phase2() {
        return $this->get('zatca_phase') === 'phase2';
    }

    /**
     * Check if we're in sandbox environment.
     */
    public function is_sandbox() {
        return $this->get('zatca_environment') === 'sandbox';
    }

    /**
     * Check if current environment is simulation.
     */
    public function is_simulation() {
        return $this->get('zatca_environment', 'sandbox') === 'simulation';
    }

    /**
     * Check if current environment is production.
     */
    public function is_production() {
        return $this->get('zatca_environment', 'sandbox') === 'production';
    }

    /**
     * Check if debug mode is enabled.
     */
    public function is_debug() {
        return $this->get('zatca_debug') === 'yes';
    }

    /**
     * Check if auto generation is enabled.
     */
    public function is_auto_generate() {
        return $this->get('zatca_auto_generate') === 'yes';
    }

    /**
     * Check if auto submission is enabled.
     */
    public function is_auto_submit() {
        return $this->get('zatca_auto_submit') === 'yes';
    }

    /**
     * Get simulation OTP.
     */
    public function get_simulation_otp() {
        return $this->get('zatca_simulation_otp', '');
    }

    /**
     * Get production OTP.
     */
    public function get_production_otp() {
        return $this->get('zatca_production_otp', '');
    }

    /**
     * Get simulation Accept-Version header.
     */
    public function get_simulation_accept_version() {
        return $this->get('zatca_simulation_accept_version', 'V2');
    }

    /**
     * Get company information as array.
     */
    public function get_company_info() {
        return array(
            'name' => $this->get('zatca_seller_name'),
            'vat_number' => $this->get('zatca_vat_number'),
            'business_category' => $this->get('zatca_business_category'),
            'building_number' => $this->get('zatca_building_number'),
            'street_name' => $this->get('zatca_street_name'),
            'district' => $this->get('zatca_district'),
            'city' => $this->get('zatca_city'),
            'postal_code' => $this->get('zatca_postal_code'),
            'country_code' => $this->get('zatca_country_code'),
        );
    }

    /**
     * Validate required company information.
     */
    public function validate_company_info() {
        $required_fields = array(
            'zatca_seller_name',
            'zatca_vat_number',
            'zatca_building_number',
            'zatca_street_name',
            'zatca_district',
            'zatca_city',
            'zatca_postal_code'
        );

        $missing_fields = array();

        foreach ($required_fields as $field) {
            if (empty($this->get($field))) {
                $missing_fields[] = $field;
            }
        }

        if (!$this->validate_vat_number($this->get('zatca_vat_number'))) {
            $missing_fields[] = 'zatca_vat_number_invalid';
        }

        return empty($missing_fields) ? true : $missing_fields;
    }

    /**
     * Get invoice settings as array.
     */
    public function get_invoice_settings() {
        return array(
            'csr_invoice_type' => $this->get('zatca_csr_invoice_type'),
            'counter_prefix' => $this->get('zatca_invoice_counter_prefix'),
            'counter_start' => (int) $this->get('zatca_invoice_counter_start'),
            'qr_size' => (int) $this->get('zatca_qr_size'),
            'qr_position' => $this->get('zatca_qr_position'),
        );
    }

    /**
     * Get certificate settings as array.
     */
    public function get_certificate_settings() {
        return array(
            'csr_common_name' => $this->get('zatca_csr_common_name'),
            'csr_serial_number' => $this->get('zatca_csr_serial_number'),
            'csr_organization_identifier' => $this->get('zatca_csr_organization_identifier'),
            'csr_organization_unit' => $this->get('zatca_csr_organization_unit'),
            'csr_organization_name' => $this->get('zatca_csr_organization_name'),
            'csr_country' => $this->get('zatca_csr_country'),
            'csr_invoice_type' => $this->get('zatca_csr_invoice_type'),
            'csr_location_address' => $this->get('zatca_csr_location_address'),
            'csr_industry_business_category' => $this->get('zatca_csr_industry_business_category'),
        );
    }

    /**
     * Validate all settings.
     */
    public function validate_all() {
        $errors = array();

        foreach ($this->settings as $field_name => $value) {
            $validation_result = $this->validate_field($field_name, $value);
            if (is_wp_error($validation_result)) {
                $errors[$field_name] = $validation_result->get_error_message();
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Export settings as JSON.
     */
    public function export_settings() {
        return wp_json_encode($this->settings, JSON_PRETTY_PRINT);
    }

    /**
     * Import settings from JSON.
     */
    public function import_settings($json_data) {
        $settings = json_decode($json_data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON data provided.', 'zatca-invoicing'));
        }

        $errors = array();

        foreach ($settings as $key => $value) {
            if ($this->get_field_schema($key)) {
                $validation_result = $this->set($key, $value);
                if (is_wp_error($validation_result)) {
                    $errors[$key] = $validation_result->get_error_message();
                }
            }
        }

        if (!empty($errors)) {
            return new WP_Error('import_validation_failed', __('Some settings failed validation.', 'zatca-invoicing'), $errors);
        }

        $this->load_settings();
        return true;
    }
} 