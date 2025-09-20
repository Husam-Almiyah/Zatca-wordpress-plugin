<?php
/**
 * Plugin Name: Zatca-wordpress-plugin
 * Plugin URI: https://github.com/Husam-Almiyah/Zatca-wordpress-plugin
 * Description: A comprehensive WordPress plugin that enables Saudi Arabian businesses to comply with ZATCA (Zakat, Tax and Customs Authority) e-invoicing requirements through WooCommerce integration.
 * Version: 1.0.0
 * Author: Husam Al-Miyah
 * Author URI: https://github.com/Husam-Almiyah
 * Text Domain: zatca-invoicing
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 5.9.3
 * WC requires at least: 6.0
 * WC tested up to: 6.4.1
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZATCA_INVOICING_VERSION', '1.0.0');
define('ZATCA_INVOICING_PLUGIN_FILE', __FILE__);
define('ZATCA_INVOICING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZATCA_INVOICING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZATCA_INVOICING_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main ZATCA Invoicing Class
 */
final class ZATCA_EInvoicing {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Main ZATCA_EInvoicing Instance.
     *
     * Ensures only one instance of ZATCA_EInvoicing is loaded or can be loaded.
     *
     * @return ZATCA_EInvoicing - Main instance.
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
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 0);
        add_action('plugins_loaded', array($this, 'plugins_loaded'), 11);
    }

    /**
     * Initialize the plugin after all plugins are loaded.
     */
    public function plugins_loaded() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load plugin textdomain
        load_plugin_textdomain('zatca-invoicing', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize classes
        $this->includes();
        $this->init_classes();
    }

    /**
     * Initialize plugin functionality.
     */
    public function init() {
        // This is called early, before plugins_loaded
        do_action('zatca_invoicing_init');
    }

    /**
     * Include required files.
     */
    private function includes() {
        // Core utility classes
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-qr-generator.php';
        
        // Core includes
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-settings.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-phase1.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-phase2.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-api-manager.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-certificate-manager.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/class-zatca-woocommerce-integration.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/XML/XMLGenerator.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Signature/InvoiceSigner.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Schema.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Helpers/Certificate.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Helpers/InvoiceExtension.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Helpers/InvoiceSignatureBuilder.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Helpers/QRCodeGenerator.php';
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Helpers/Storage.php';

        // Admin includes
        if (is_admin()) {
            // Use correct case-sensitive paths for cross-platform compatibility
            include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Admin/class-zatca-admin.php';
        }

        // API includes
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/API/class-zatca-api.php';
    }

    /**
     * Initialize plugin classes.
     */
    private function init_classes() {
        // Initialize settings
        ZATCA_Settings::instance();

        // Initialize integrations
        ZATCA_WooCommerce_Integration::instance();

        // Initialize admin
        if (is_admin()) {
            ZATCA_Admin::instance();
            // Unified admin combines original and enhanced admin features
        }

        // Initialize API
        ZATCA_API::instance();
    }

    /**
     * Check if WooCommerce is active.
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * WooCommerce fallback notice.
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . sprintf(
            esc_html__('ZATCA E-Invoicing requires WooCommerce to be installed and active. You can download %s here.', 'zatca-invoicing'),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        ) . '</strong></p></div>';
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Create database tables if needed
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Schedule any necessary cron jobs
        $this->schedule_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('zatca_invoicing_daily_tasks');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create plugin database tables.
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();


        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Table for storing certificates (supports dual certificate system per environment)
        $table_name = $wpdb->prefix . 'zatca_certificates';
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            certificate_name varchar(255) NOT NULL,
            certificate_type enum('onboarding', 'production', 'csr') NOT NULL DEFAULT 'onboarding',
            environment enum('sandbox', 'simulation', 'production') NOT NULL DEFAULT 'sandbox',
            certificate_data longtext NOT NULL,
            csr_data longtext NOT NULL,
            private_key_data longtext NOT NULL,
            binary_security_token longtext NULL,
            secret varchar(255) NULL,
            request_id varchar(255) NULL COMMENT 'compliance_request_id for production cert requests',
            is_active tinyint(1) DEFAULT 0,
            invoice_types varchar(10) DEFAULT '0100' COMMENT '0100=simplified, 1000=standard, 1100=both',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY environment_type_active (environment, certificate_type, is_active),
            KEY environment (environment),
            KEY certificate_type (certificate_type),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta($sql);

        // Table for tracking onboarding sessions per environment
        $table_name = $wpdb->prefix . 'zatca_onboarding_sessions';
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            environment enum('sandbox', 'simulation', 'production') NOT NULL,
            session_status enum('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
            invoice_types varchar(10) NOT NULL COMMENT 'Types to test: 0100, 1000, or 1100',
            onboarding_cert_id int(11) NULL,
            production_cert_id int(11) NULL,
            compliance_tests json NULL COMMENT 'Results of compliance tests for each invoice type',
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (onboarding_cert_id) REFERENCES {$wpdb->prefix}zatca_certificates(id) ON DELETE SET NULL,
            FOREIGN KEY (production_cert_id) REFERENCES {$wpdb->prefix}zatca_certificates(id) ON DELETE SET NULL,
            UNIQUE KEY environment_unique (environment),
            KEY session_status (session_status)
        ) $charset_collate;";

        dbDelta($sql);

        // Table for tracking invoice submissions with flow separation
        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            order_id int(11) NOT NULL,
            invoice_type varchar(255) NOT NULL,
            submission_type enum('phase1', 'onboarding', 'production') NOT NULL,
            certificate_id int(11) NULL,
            environment enum('sandbox', 'simulation', 'production') NOT NULL,
            status enum('pending', 'completed', 'submitted', 'cleared', 'reported', 'failed', 'validated') DEFAULT 'pending',
            irn varchar(255) NULL,
            pih varchar(255) NULL,
            qr_code text NULL,
            signed_xml longtext NULL,
            invoice_hash varchar(255) NULL,
            uuid varchar(255) NULL,
            zatca_response json NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            PRIMARY KEY (id),
            FOREIGN KEY (certificate_id) REFERENCES {$wpdb->prefix}zatca_certificates(id) ON DELETE SET NULL,
            UNIQUE KEY order_submission_type (order_id, submission_type, invoice_type),
            KEY order_id (order_id),
            KEY submission_type (submission_type),
            KEY environment (environment),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Set default plugin options.
     */
    private function set_default_options() {
        $default_options = array(
            'zatca_enabled' => 'yes',
            'zatca_phase' => 'phase1',
            'zatca_environment' => 'sandbox',
            'zatca_seller_name' => '',
            'zatca_vat_number' => '',
            'zatca_business_category' => '',
            'zatca_csr_invoice_type' => '0100',
            'zatca_auto_generate' => 'yes',
            'zatca_qr_size' => '150',
            'zatca_debug' => 'no'
        );

        foreach ($default_options as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Schedule plugin events.
     */
    private function schedule_events() {
        if (!wp_next_scheduled('zatca_invoicing_daily_tasks')) {
            wp_schedule_event(time(), 'daily', 'zatca_invoicing_daily_tasks');
        }
    }

    /**
     * Get the plugin version.
     */
    public function get_version() {
        return ZATCA_INVOICING_VERSION;
    }

    /**
     * Get the plugin file.
     */
    public function get_plugin_file() {
        return ZATCA_INVOICING_PLUGIN_FILE;
    }

    /**
     * Get the plugin directory.
     */
    public function get_plugin_dir() {
        return ZATCA_INVOICING_PLUGIN_DIR;
    }

    /**
     * Get the plugin URL.
     */
    public function get_plugin_url() {
        return ZATCA_INVOICING_PLUGIN_URL;
    }
}

/**
 * Main instance of ZATCA_EInvoicing.
 *
 * Returns the main instance of ZATCA_EInvoicing to prevent the need to use globals.
 *
 * @return ZATCA_EInvoicing
 */
function ZATCA_EInvoicing() {
    return ZATCA_EInvoicing::instance();
}

// Initialize the plugin
ZATCA_EInvoicing(); 