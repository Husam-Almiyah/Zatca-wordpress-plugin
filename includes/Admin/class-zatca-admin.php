<?php
/**
 * ZATCA Admin Class
 * 
 * This class is responsible for the admin interface of the ZATCA plugin.
 * 
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA Admin Class
 */
class ZATCA_Admin {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Main ZATCA_Admin Instance.
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
     * Initialize admin functionality.
     */
    private function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Plugin action links
        add_filter('plugin_action_links_' . plugin_basename(ZATCA_INVOICING_PLUGIN_FILE), array($this, 'plugin_action_links'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));

        // Initialize admin settings page
        include_once ZATCA_INVOICING_PLUGIN_DIR . 'includes/Admin/class-zatca-admin-settings.php';
        ZATCA_Admin_Settings::instance();
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('ZATCA', 'zatca-invoicing'),
            __('ZATCA', 'zatca-invoicing'),
            'manage_options',
            'zatca-e-invoicing',
            array(ZATCA_Admin_Settings::instance(), 'settings_page_content')
        );
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_admin_scripts($hook) {
        // Enqueue scripts only on our plugin's admin pages
        if (strpos($hook, 'zatca-e-invoicing') === false) {
            return;
        }
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
     * Add plugin action links.
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=zatca-e-invoicing') . '">' . __('Settings', 'zatca-invoicing') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Display admin notices.
     */
    public function admin_notices() {
        // Check if ZATCA is enabled and settings need attention
        if ($this->settings->is_enabled()) {
            $validation_result = $this->settings->validate_all();
            if ($validation_result !== true) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . __('ZATCA Settings:', 'zatca-invoicing') . '</strong> ';
                echo __('Your ZATCA settings need to be reviewed they don\'t meet the ZATCA requirements. ', 'zatca-invoicing');
                echo '<a href="' . admin_url('admin.php?page=zatca-e-invoicing') . '">' . __('Review Settings', 'zatca-invoicing') . '</a>';
                echo '</p></div>';
            }
        }
    }
}