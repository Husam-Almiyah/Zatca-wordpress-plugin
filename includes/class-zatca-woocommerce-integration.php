<?php
/**
 * ZATCA WooCommerce Integration Class
 *
 * Handles integration with WooCommerce for automatic invoice generation
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA WooCommerce Integration Class
 */
class ZATCA_WooCommerce_Integration {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Phase 1 handler
     */
    private $phase1;

    /**
     * Phase 2 handler
     */
    private $phase2;

    /**
     * Certificate manager
     */
    private $certificate_manager;

    /**
     * Main ZATCA_WooCommerce_Integration Instance.
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
     * Initialize WooCommerce integration.
     */
    private function init() {
        // Initialize handlers
        $this->phase1 = ZATCA_Phase1::instance();
        $this->phase2 = ZATCA_Phase2::instance();

        // Setup hooks only if ZATCA is enabled
        if ($this->settings->is_enabled()) {
            $this->setup_hooks();
        }
    }

    /**
     * Setup WooCommerce hooks.
     */
    private function setup_hooks() {
        // Order status change hooks
        add_action('woocommerce_order_status_refunded', array($this, 'generate_invoice_on_order_refunded'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'generate_invoice_on_order_processing'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'generate_invoice_on_order_completed'), 10, 1);

        // Invoice display hooks
        add_action('woocommerce_email_after_order_table', array($this, 'add_zatca_qr_to_email'), 20, 4);
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_zatca_qr_on_order_details'), 10, 1);

        // Admin order hooks
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_zatca_info_in_admin'), 10, 1);
        add_action('add_meta_boxes', array($this, 'add_zatca_meta_box'));

        // Order meta display
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_zatca_action_buttons'), 10, 1);

        // Ajax hooks for manual generation
        add_action('wp_ajax_zatca_generate_invoice', array($this, 'ajax_generate_invoice'));
        add_action('wp_ajax_zatca_regenerate_qr', array($this, 'ajax_regenerate_qr'));
        add_action('wp_ajax_zatca_submit_invoice', array($this, 'ajax_submit_invoice'));

        // REST API hooks
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Invoice template hooks (for delivery notes plugin compatibility)
        add_action('wcdn_after_branding', array($this, 'add_qr_to_delivery_note'), 10, 1);

        // Custom order columns
        add_filter('manage_edit-shop_order_columns', array($this, 'add_zatca_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'populate_zatca_order_column'), 10, 2);

        // PDF invoice hooks (if using PDF invoice plugins)
        add_action('woocommerce_pdf_invoice_after_order_details', array($this, 'add_qr_to_pdf_invoice'), 10, 2);
    }

    /**
     * Generate invoice when order status changes to processing.
     *
     * @param int $order_id
     */
    public function generate_invoice_on_order_processing($order_id) {
        if (!$this->settings->is_auto_generate()) {
            return;
        }

        if (!$this->has_zatca_invoice($order_id)) {
            $this->generate_zatca_invoice($order_id, 'processing');
        }
    }

    /**
     * Generate invoice when order status changes to completed.
     *
     * @param int $order_id
     */
    public function generate_invoice_on_order_completed($order_id) {
        return;
        if (!$this->settings->is_auto_generate()) {
            return;
        }

        // Only generate if not already generated
        if (!$this->has_zatca_invoice($order_id)) {
            $this->generate_zatca_invoice($order_id, 'completed');
        }
    }

    /**
     * Generate invoice when order status changes to refunded.
     *
     * @param int $order_id
     */
    public function generate_invoice_on_order_refunded($order_id) {
        if (!$this->settings->is_auto_generate()) {
            return;
        }

        if (!$this->has_zatca_invoice($order_id, 'simplified-credit')) {
            $this->generate_zatca_invoice($order_id, 'refunded', 'simplified-credit');
        }
    }

    /**
     * Generate ZATCA invoice based on phase.
     *
     * @param int $order_id
     * @param string $trigger
     * @return array|WP_Error
     */
    public function generate_zatca_invoice($order_id, $trigger = 'manual', $invoice_type = null) {
        try {
            // Check if already processing
            if (get_transient('zatca_generating_' . $order_id)) {
                return new WP_Error('already_processing', __('Invoice generation already in progress.', 'zatca-invoicing'));
            }

            // Set processing flag
            set_transient('zatca_generating_' . $order_id, true, 300); // 5 minutes

            $order = wc_get_order($order_id);
            if (!$order) {
                delete_transient('zatca_generating_' . $order_id);
                return new WP_Error('invalid_order', __('Invalid order ID provided.', 'zatca-invoicing'));
            }

            // Determine invoice type if not explicitly provided
            if (is_null($invoice_type)) {
                $invoice_type = $this->determine_invoice_type($order);
            }

            $result = array();

            // Always generate Phase 1 QR code (required for both phases)
            $qr_result = $this->phase1->generate_qr_code($order_id, array(), $invoice_type);
            if (!is_wp_error($qr_result)) {
                $result['phase1'] = $qr_result;
            } else {
                $result['phase1_error'] = $qr_result->get_error_message();
            }

            // Generate Phase 2 XML if enabled
            if ($this->settings->is_phase2()) {
                // Use production certificate for live invoice generation from order page
                $xml_result = $this->phase2->generate_xml_invoice($order_id, $invoice_type, ['certificate_type' => 'production']);
                if (!is_wp_error($xml_result)) {
                    $result['phase2'] = $xml_result;

                    // Auto-submit to ZATCA if configured
                    if ($this->should_auto_submit()) {
                        $submit_result = $this->phase2->submit_invoice_to_zatca($order_id, $invoice_type, 'production');
                        if (!is_wp_error($submit_result)) {
                            $result['submission'] = $submit_result;
                        } else {
                            $result['submission_error'] = $submit_result->get_error_message();
                        }
                    }
                } else {
                    $result['phase2_error'] = $xml_result->get_error_message();
                }
            }

            // Log generation
            $this->log_invoice_generation($order_id, $trigger, $result);

            // Clear processing flag
            delete_transient('zatca_generating_' . $order_id);

            // Send notifications if configured
            $this->send_notifications($order_id, $result);

            return $result;

        } catch (Exception $e) {
            delete_transient('zatca_generating_' . $order_id);
            return new WP_Error('generation_failed', $e->getMessage());
        }
    }

    /**
     * Determine the invoice type based on order properties and CSR invoice type setting.
     *
     * @param WC_Order $order
     * @return string Returns invoice type like 'simplified', 'standard', etc. with auto-detected credit/debit
     */
    public function determine_invoice_type(WC_Order $order) {
        // Get the CSR invoice type setting which determines allowed invoice types
        $csr_invoice_type = $this->settings->get('zatca_csr_invoice_type', '0100');
        
        // Determine base type based on CSR setting and order context
        $base_type = $this->get_base_invoice_type($csr_invoice_type, $order);
        
        // Auto-detect document type (invoice, credit, debit) from order data
        $document_type = $this->detect_document_type($order);
        
        // Combine base type with document type
        if ($document_type === 'credit') {
            return $base_type . '-credit';
        } elseif ($document_type === 'debit') {
            return $base_type . '-debit';
        }
        
        return $base_type; // Regular invoice
    }
    
    /**
     * Get base invoice type based on CSR setting and order context.
     *
     * @param string $csr_invoice_type
     * @param WC_Order $order
     * @return string 'simplified' or 'standard'
     */
    private function get_base_invoice_type($csr_invoice_type, $order) {
        switch ($csr_invoice_type) {
            case '0100': // Simplified only
                return 'simplified';
                
            case '1000': // Standard only
                return 'standard';
                
            case '1100': // Both - determine from order context
                // Check if customer has VAT number (B2B indicator)
                $customer_vat_number = $order->get_meta('_billing_vat_number');
                return !empty($customer_vat_number) ? 'standard' : 'simplified';
                
            default:
                return 'simplified'; // Fallback
        }
    }
    
    /**
     * Detect document type (invoice, credit, debit) from order data.
     *
     * @param WC_Order $order
     * @return string 'invoice', 'credit', or 'debit'
     */
    private function detect_document_type($order) {
        // Check for refunds (credit notes)
        if ($order->get_total_refunded() > 0 || $order->get_status() === 'refunded') {
            return 'credit';
        }

        // Check for debit notes based on custom order meta
        if ($order->get_meta('_zatca_invoice_type') === 'debit') {
            return 'debit';
        }

        return 'invoice'; // Regular invoice
    }

    /**
     * Add ZATCA QR code to email.
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param WC_Email $email
     */
    public function add_zatca_qr_to_email($order, $sent_to_admin, $plain_text, $email) {
        // Only show in customer emails and invoice emails
        if ($sent_to_admin || $plain_text) {
            return;
        }

        $allowed_email_types = array('customer_invoice', 'customer_completed_order', 'customer_processing_order');
        if (!in_array($email->id, $allowed_email_types)) {
            return;
        }

        $this->display_zatca_qr($order);
    }

    /**
     * Display ZATCA QR code on order details page.
     *
     * @param WC_Order $order
     */
    public function display_zatca_qr_on_order_details($order) {
        $this->display_zatca_qr($order);
    }

    /**
     * Display ZATCA QR code.
     *
     * @param WC_Order $order
     */
    private function display_zatca_qr($order) {
        // todo: implement position feature
        $qr_position = $this->settings->get('zatca_qr_position', 'after_order_details');
        
        // Get QR code data
        $qr_data = $this->phase1->get_qr_code_data($order->get_id());
        
        if (!$qr_data || !isset($qr_data['qr_code'])) {
            // Generate QR code if auto-generation is enabled
            if ($this->settings->is_auto_generate()) {
                $this->generate_zatca_invoice($order->get_id(), 'display');
                $qr_data = $this->phase1->get_qr_code_data($order->get_id());
            }
        }

        if ($qr_data && isset($qr_data['qr_code'])) {
            $this->render_qr_code($qr_data, $order);
        }
    }

    /**
     * Render QR code HTML.
     *
     * @param array $qr_data
     * @param WC_Order $order
     */
    private function render_qr_code($qr_data, $order) {
        $qr_size = $this->settings->get('zatca_qr_size', 150);
        $qr_url = $qr_data['qr_code_url']; // Already a URL from get_qr_code_data
        $qr_tlv_b64 = $qr_data['qr_code'];
        
        // Render QR as image from TLV base64 (library or fallback URL)
        $qr_img_src = ZATCA_QR_Generator::generate_qr_image($qr_tlv_b64, 260);
        
        ?>
            <img src="<?php echo esc_attr($qr_img_src); ?>" 
                    alt="<?php _e('ZATCA QR Code', 'zatca-invoicing'); ?>" 
                    style="width: <?php echo $qr_size; ?>px; height: <?php echo $qr_size; ?>px; border: 1px solid #ccc;">
        <?php
    }

    /**
     * Display ZATCA information in admin order page.
     *
     * @param WC_Order $order
     */
    public function display_zatca_info_in_admin($order) {
        $environment = $this->settings->get('zatca_environment', 'sandbox');
        
        // Check if we have production certificate for this environment
        $production_cert = $this->certificate_manager->get_active_certificate($environment, 'production');
        $has_production_cert = !is_wp_error($production_cert);
        
        // Get production invoice submission status (not onboarding)
        $xml_data = null;
        if ($this->settings->is_phase2() && $has_production_cert) {
            $invoice_type = $this->determine_invoice_type($order);
            $xml_data = $this->phase2->get_xml_invoice_data($order->get_id(), 'production', $invoice_type);
        }
        
        ?>
        <div class="zatca-admin-info" style="margin: 10px 0; padding: 10px; background: #f1f1f1; border-left: 4px solid #0073aa;">
            <h4><?php _e('ZATCA Production Status', 'zatca-invoicing'); ?></h4>
            
            <?php if ($has_production_cert): ?>
                <p><strong><?php _e('Production Certificate:', 'zatca-invoicing'); ?></strong> 
                   <span style="color: green;">✅ <?php _e('Active', 'zatca-invoicing'); ?></span></p>
                
                <?php if ($xml_data): ?>
                    <p><strong><?php _e('Phase 2 XML:', 'zatca-invoicing'); ?></strong> 
                       <span style="color: green;">✓ <?php _e('Generated', 'zatca-invoicing'); ?></span></p>
                    <?php if (!empty($xml_data['uuid'])): ?>
                        <p><strong><?php _e('Invoice UUID:', 'zatca-invoicing'); ?></strong> 
                           <code><?php echo esc_html($xml_data['uuid']); ?></code></p>
                    <?php endif; ?>
                    <?php if ($xml_data['status']): ?>
                        <p><strong><?php _e('ZATCA Status:', 'zatca-invoicing'); ?></strong> 
                        <span class="zatca-status-<?php echo ucfirst($xml_data['status'] ?? 'N/A'); ?>">
                            <?php echo ucfirst($xml_data['status'] ?? 'N/A'); ?>
                        </span></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><strong><?php _e('Phase 2 XML:', 'zatca-invoicing'); ?></strong> 
                       <span style="color: orange;">⚠ <?php _e('Not Generated', 'zatca-invoicing'); ?></span></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add ZATCA meta box to admin order page.
     */
    public function add_zatca_meta_box() {
        add_meta_box(
            'zatca-invoice-actions',
            __('ZATCA E-Invoicing Actions', 'zatca-invoicing'),
            array($this, 'render_zatca_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Render ZATCA meta box content.
     *
     * @param WP_Post $post
     */
    public function render_zatca_meta_box($post) {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }

        $environment = $this->settings->get('zatca_environment', 'sandbox');
        
        // Check if we have production certificate for this environment
        $production_cert = $this->certificate_manager->get_active_certificate($environment, 'production');
        $has_production_cert = !is_wp_error($production_cert);
        
        // Get production invoice submission status (not onboarding)
        $xml_data = null;
        if ($this->settings->is_phase2() && $has_production_cert) {
            $invoice_type = $this->determine_invoice_type($order);
            $xml_data = $this->phase2->get_xml_invoice_data($order->get_id(), 'production', $invoice_type);
        }
        
        ?>
        <div class="zatca-meta-box-content">
            <p><strong><?php _e('ZATCA Production Status:', 'zatca-invoicing'); ?></strong></p>
            <ul style="margin-left: 20px;">
                <?php if ($has_production_cert): ?>
                    <li>Production Certificate: ✅ Active</li>
                    <li>XML Invoice: <?php echo $xml_data ? '✅ Generated' : '⚠ Not Generated'; ?></li>
                    <?php if ($xml_data): ?>
                        <li>ZATCA Status: <?php echo ucfirst($xml_data['status'] ?? 'N/A'); ?></li>
                    <?php endif; ?>
                <?php else: ?>
                    <li>Production Certificate: ❌ Not Available</li>
                    <li><em>Complete onboarding process first to enable live submissions</em></li>
                <?php endif; ?>
            </ul>

            <p><strong><?php _e('Actions:', 'zatca-invoicing'); ?></strong></p>
            <?php if ($has_production_cert): ?>
                <p>
                    <button type="button" class="button zatca-generate-invoice" data-order-id="<?php echo $order->get_id(); ?>">
                        <?php _e('Generate Invoice', 'zatca-invoicing'); ?>
                    </button>
                </p>
                
                <?php if ($xml_data && ($xml_data['status'] ?? 'pending') !== 'submitted'): ?>
                <p>
                    <button type="button" class="button button-primary zatca-submit-invoice" data-order-id="<?php echo $order->get_id(); ?>">
                        <?php _e('Submit to ZATCA', 'zatca-invoicing'); ?>
                    </button>
                </p>
                <?php endif; ?>
            <?php else: ?>
                <p><em><?php _e('Complete onboarding process to enable invoice actions', 'zatca-invoicing'); ?></em></p>
            <?php endif; ?>

            <div class="zatca-actions-result" style="margin-top: 10px;"></div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.zatca-generate-invoice, .zatca-submit-invoice').on('click', function() {
                var $button = $(this);
                var orderId = $button.data('order-id');
                var action = 'zatca_generate_invoice';
                
                if ($button.hasClass('zatca-submit-invoice')) {
                    action = 'zatca_submit_invoice';
                }

                $button.prop('disabled', true).text('Processing...');
                $('.zatca-actions-result').html('<div class="notice notice-info"><p>Processing...</p></div>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: action,
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('zatca_action'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.zatca-actions-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            location.reload(); // Refresh to show updated status
                        } else {
                            $('.zatca-actions-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function(response) {
                        $('.zatca-actions-result').html('<div class="notice notice-error"><p>An error occurred.</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text($button.data('original-text') || 'Action');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Add QR code to delivery note (WCDN plugin compatibility).
     *
     * @param WC_Order $order
     */
    public function add_qr_to_delivery_note($order) {
        $this->display_zatca_qr($order);
    }

    /**
     * Add ZATCA column to orders list.
     *
     * @param array $columns
     * @return array
     */
    public function add_zatca_order_column($columns) {
        $columns['zatca_status'] = __('ZATCA Status', 'zatca-invoicing');
        return $columns;
    }

    /**
     * Populate ZATCA column in orders list.
     *
     * @param string $column
     * @param int $post_id
     */
    public function populate_zatca_order_column($column, $post_id) {
        if ($column !== 'zatca_status') {
            return;
        }

        $qr_data = $this->phase1->get_qr_code_data($post_id);
        $order = wc_get_order($post_id);
        $invoice_type = 'simplified';
        if ($order) {
            $invoice_type = $this->determine_invoice_type($order);
        }

        $xml_data = $this->settings->is_phase2() ? $this->phase2->get_xml_invoice_data($post_id) : null;

        if ($this->settings->is_phase2() && $xml_data) {
            $status = $xml_data['status'] ?? 'N/A';
            echo '<span class="zatca-status-' . esc_attr($status) . '">';
            echo esc_html(ucfirst($status));
            echo '</span>';
        } elseif ($qr_data) {
            echo '<span style="color: green;">QR Generated</span>';
        } else {
            echo '<span style="color: orange;">Pending</span>';
        }
    }

    /**
     * Ajax handler for generating invoice.
     */
    public function ajax_generate_invoice() {
        check_ajax_referer('zatca_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'zatca-invoicing'));
        }

        $order_id = intval($_POST['order_id']);
        $result = $this->generate_zatca_invoice($order_id, 'manual');

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Invoice generated successfully.', 'zatca-invoicing')));
        }
    }

    /**
     * Ajax handler for regenerating QR code.
     */
    public function ajax_regenerate_qr() {
        check_ajax_referer('zatca_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'zatca-invoicing')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'zatca-invoicing')));
        }

        // Determine invoice type for the order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'zatca-invoicing')));
        }
        
        $invoice_type = $this->determine_invoice_type($order);
        $result = $this->phase1->generate_qr_code($order_id, array(), $invoice_type); // Regenerate via delete + generate
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('QR code regenerated successfully.', 'zatca-invoicing')));
        }
    }

    public function ajax_submit_invoice() {
        check_ajax_referer('zatca_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'zatca-invoicing'));
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'zatca-invoicing')));
        }

        $invoice_type = $this->determine_invoice_type($order);
        $result = $this->phase2->submit_invoice_to_zatca($order_id, $invoice_type, 'production');

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Invoice submitted to ZATCA successfully.', 'zatca-invoicing')));
        }
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        register_rest_route('zatca/v1', '/orders/(?P<id>\d+)/invoice', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_generate_invoice'),
            'permission_callback' => array($this, 'rest_permissions_check'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));

        register_rest_route('zatca/v1', '/orders/(?P<id>\d+)/qr', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_qr_code'),
            'permission_callback' => array($this, 'rest_permissions_check'),
        ));
    }

    /**
     * REST API permission check.
     */
    public function rest_permissions_check() {
        return current_user_can('manage_woocommerce');
    }

    /**
     * REST API generate invoice endpoint.
     */
    public function rest_generate_invoice($request) {
        $order_id = $request->get_param('id');
        $result = $this->generate_zatca_invoice($order_id, 'api');

        if (is_wp_error($result)) {
            return new WP_Error('generation_failed', $result->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response($result);
    }

    /**
     * REST API get QR code endpoint.
     */
    public function rest_get_qr_code($request) {
        $order_id = $request->get_param('id');
        $qr_data = $this->phase1->get_qr_code_data($order_id);

        if (!$qr_data) {
            return new WP_Error('no_qr_code', __('No QR code found for this order.', 'zatca-invoicing'), array('status' => 404));
        }

        return rest_ensure_response($qr_data);
    }

    /**
     * Check if order has ZATCA invoice.
     *
     * @param int $order_id
     * @param string|null $invoice_type Optional. Invoice type to check for (e.g., 'simplified', 'simplified-credit').
     * @return bool
     */
    public function has_zatca_invoice($order_id, $invoice_type = 'simplified') {
        $qr_data = $this->phase1->get_qr_code_data($order_id, $invoice_type);
        return !empty($qr_data);
    }

    /**
     * Check if order has ZATCA submission.
     *
     * @param int $order_id
     * @return bool
     */
    public function has_zatca_submission($order_id) {
        if (!$this->settings->is_phase2()) {
            return false;
        }
        
        $xml_data = $this->phase2->get_xml_invoice_data($order_id, 'production');
        return $xml_data && ($xml_data['status'] === 'reported' || $xml_data['status'] === 'cleared');
    }

    /**
     * Check if should auto-submit to ZATCA.
     *
     * @return bool
     */
    private function should_auto_submit() {
        return $this->settings->is_auto_submit();
    }

    /**
     * Log invoice generation.
     *
     * @param int $order_id
     * @param string $trigger
     * @param mixed $result
     */
    private function log_invoice_generation($order_id, $trigger, $result) {
        if ($this->settings->is_debug()) {
            $log_message = sprintf(
                '[ZATCA Integration] Order #%d - %s trigger: %s - result: %s',
                $order_id,
                $trigger,
                json_encode($result)
            );
            
            error_log($log_message);
        }
    }

    /**
     * Send notifications if configured.
     *
     * @param int $order_id
     * @param array $result
     */
    private function send_notifications($order_id, $result) {
        if ($this->settings->get('zatca_email_notifications') !== 'yes') {
            return;
        }

        $order = wc_get_order($order_id);
        $notification_email = $this->settings->get('zatca_notification_email', get_option('admin_email'));

        $subject = sprintf(__('ZATCA Invoice Generated - Order #%s', 'zatca-invoicing'), $order->get_order_number());
        
        $message = sprintf(__('ZATCA invoice has been generated for order #%s.', 'zatca-invoicing'), $order->get_order_number());
        $message .= "\n\n" . __('Generation Results:', 'zatca-invoicing') . "\n";
        
        if (isset($result['phase1'])) {
            $message .= "- " . __('Phase 1 QR Code: Generated', 'zatca-invoicing') . "\n";
        }
        if (isset($result['phase2'])) {
            $message .= "- " . __('Phase 2 XML: Generated', 'zatca-invoicing') . "\n";
        }
        if (isset($result['submission'])) {
            $message .= "- " . __('ZATCA Submission: Successful', 'zatca-invoicing') . "\n";
        }

        wp_mail($notification_email, $subject, $message);
    }

    /**
     * Add QR to PDF invoice (compatibility with PDF invoice plugins).
     *
     * @param WC_Order $order
     * @param string $context
     */
    public function add_qr_to_pdf_invoice($order, $context = '') {
        $this->display_zatca_qr($order);
    }
} 