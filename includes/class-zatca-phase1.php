<?php
/**
 * ZATCA Phase 1 Handler Class
 *
 * Handles Phase 1 implementation - QR Code generation for simplified invoices
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Famcare\ZatcaInvoicing\Helpers\QRCodeGenerator;
use Famcare\ZatcaInvoicing\Models\Tags\Seller;
use Famcare\ZatcaInvoicing\Models\Tags\TaxNumber;
use Famcare\ZatcaInvoicing\Models\Tags\InvoiceDate;
use Famcare\ZatcaInvoicing\Models\Tags\InvoiceTotalAmount;
use Famcare\ZatcaInvoicing\Models\Tags\InvoiceTaxAmount;

/**
 * ZATCA Phase 1 Class
 */
class ZATCA_Phase1 {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Main ZATCA_Phase1 Instance.
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
     * Initialize Phase 1 functionality.
     */
    private function init() {
        // Load required libraries
        $this->load_dependencies();
        
        // Setup hooks
        add_action('zatca_generate_qr_code', array($this, 'generate_qr_code'), 10, 2);
    }

    /**
     * Load required dependencies.
     */
    private function load_dependencies() {
        // Load composer autoloader if available
        if (file_exists(ZATCA_INVOICING_PLUGIN_DIR . 'vendor/autoload.php')) {
            require_once ZATCA_INVOICING_PLUGIN_DIR . 'vendor/autoload.php';
        }
    }

    /**
     * Generate TLV QR Code for an order.
     *
     * @param int $order_id WooCommerce order ID
     * @param array $override_data Optional data to override order data
     * @param string $invoice_type Invoice type (e.g., 'simplified', 'simplified-credit')
     * @return array|WP_Error QR code data or error
     */
    public function generate_qr_code($order_id, $override_data = array(), $invoice_type = 'simplified') {
        try {
            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('invalid_order', __('Invalid order ID provided.', 'zatca-invoicing'));
            }

            // Prepare invoice data
            $invoice_data = $this->prepare_invoice_data($order, $override_data);

            // Validate required data
            $validation_result = $this->validate_invoice_data($invoice_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Generate QR code using ZATCA QR Generator
            $qr_result = $this->generate_zatca_qr_code_new($invoice_data);
            
            if (!is_wp_error($qr_result)) {
                // Save QR code data to database
                $this->save_qr_code_data($order_id, $qr_result, $invoice_type);

                // Log success
                $this->log_qr_generation($order_id, 'success', $qr_result);
            } else {
                // Log error
                $this->log_qr_generation($order_id, 'error', $qr_result->get_error_message());
            }

            return $qr_result;

        } catch (Exception $e) {
            $error = new WP_Error('qr_generation_failed', $e->getMessage());
            $this->log_qr_generation($order_id, 'error', $e->getMessage());
            return $error;
        }
    }

    /**
     * Prepare invoice data from WooCommerce order.
     *
     * @param WC_Order $order
     * @param array $override_data
     * @return array
     */
    private function prepare_invoice_data($order, $override_data = array()) {
        $company_info = $this->settings->get_company_info();
        
        $invoice_data = array(
            'seller_name' => $company_info['name'],
            'vat_number' => $company_info['vat_number'],
            'invoice_date' => $order->get_date_created()->format('Y-m-d\TH:i:s\Z'),
            'total_amount' => $order->get_total(),
            'tax_amount' => $order->get_total_tax(),
            'order_id' => $order->get_id(),
        );

        // Override with any provided data
        if (!empty($override_data)) {
            $invoice_data = array_merge($invoice_data, $override_data);
        }

        return apply_filters('zatca_phase1_invoice_data', $invoice_data, $order);
    }

    /**
     * Validate invoice data before QR generation.
     *
     * @param array $invoice_data
     * @return bool|WP_Error
     */
    private function validate_invoice_data($invoice_data) {
        $required_fields = array('seller_name', 'vat_number', 'invoice_date', 'total_amount', 'tax_amount');
        
        foreach ($required_fields as $field) {
            if (empty($invoice_data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Required field %s is missing.', 'zatca-invoicing'), $field));
            }
        }

        // Validate VAT number
        if (!$this->settings->validate_vat_number($invoice_data['vat_number'])) {
            return new WP_Error('invalid_vat', __('Invalid VAT number format.', 'zatca-invoicing'));
        }

        // Validate amounts
        if (!is_numeric($invoice_data['total_amount']) || !is_numeric($invoice_data['tax_amount'])) {
            return new WP_Error('invalid_amounts', __('Invoice amounts must be numeric.', 'zatca-invoicing'));
        }

        // ZATCA does not accept invoices with zero charges (free products or 100% discount)
        if ((float)$invoice_data['total_amount'] <= 0) {
            return new WP_Error('zero_amount_invoice', __('ZATCA does not accept invoices with zero or negative amounts. Please ensure the order has a positive total value.', 'zatca-invoicing'));
        }

        return true;
    }

    /**
     * Generate QR code using the new QRCodeGenerator. (Renamed from generate_zatca_qr_code)
     *
     * @param array $invoice_data
     * @return array|WP_Error
     */
    private function generate_zatca_qr_code_new($invoice_data) {
        try {
            $qrTags = [
                new Seller($invoice_data['seller_name']),
                new TaxNumber($invoice_data['vat_number']),
                new InvoiceDate($invoice_data['invoice_date']),
                new InvoiceTotalAmount(number_format((float)$invoice_data['total_amount'], 2, '.', '')),
                new InvoiceTaxAmount(number_format((float)$invoice_data['tax_amount'], 2, '.', '')),
            ];

            $qr_generator = QRCodeGenerator::createFromTags($qrTags);
            $qr_data_base64 = $qr_generator->encodeBase64();

            // Generate QR image
            $qr_image = $this->generate_qr_image($qr_data_base64);

            // Save image and get attachment ID
            $attachment_id = $this->save_qr_image($qr_image, $invoice_data['order_id']);

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            return array(
                'attachment_id' => $attachment_id,
                'qr_code_text'  => $qr_data_base64, // Store base64 encoded TLV
                'method'        => 'zatca_new'
            );

        } catch (Exception $e) {
            return new WP_Error('zatca_qr_failed_new', $e->getMessage());
        }
    }

    /**
     * Generate QR code image using chillerlan/php-qrcode.
     *
     * @param string $data Base64 encoded TLV data
     * @return string Base64 encoded image
     */
    private function generate_qr_image($data) {
        $qr_size = $this->settings->get('zatca_qr_size', 200);
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'   => QRCode::ECC_L,
            'scale'      => 5, // Adjust scale as needed
            'size'       => $qr_size,
            'imageBase64' => true,
        ]);

        $qrcode = new QRCode($options);
        return $qrcode->render($data);
    }

    /**
     * Save QR code image to uploads directory and return attachment ID.
     *
     * @param string $base64_image
     * @param int $order_id
     * @return int|WP_Error Attachment ID or error
     */
    private function save_qr_image($base64_image, $order_id) {
        try {
            // Get upload directory
            $upload_dir = wp_upload_dir();
            $zatca_dir = $upload_dir['basedir'] . '/zatca-qr-codes';
            
            // Create directory if it doesn't exist
            if (!file_exists($zatca_dir)) {
                wp_mkdir_p($zatca_dir);
            }

            // Extract image data
            $image_data = $base64_image;
            // chillerlan\QRCode with imageBase64=true returns a data URI; strip it if present
            if (strpos($image_data, 'data:image/png;base64,') === 0) {
                $image_data = substr($image_data, strlen('data:image/png;base64,'));
            }
            
            $decoded_image = base64_decode($image_data);
            // If base64 decoding fails, assume already binary PNG and use as-is
            if ($decoded_image === false) {
                $decoded_image = $base64_image;
            }
            
            // Generate filename
            $filename = 'zatca-qr-' . $order_id . '-' . time() . '.png';
            $file_path = $zatca_dir . '/' . $filename;
            
            // Save file
            $result = file_put_contents($file_path, $decoded_image);
            
            if ($result === false) {
                return new WP_Error('file_save_failed', __('Failed to save QR code image.', 'zatca-invoicing'));
            }
            
            // Create attachment in media library
            $attachment_id = $this->create_attachment($file_path, $order_id);

            if (is_wp_error($attachment_id)) {
                // Clean up the file if attachment creation failed
                unlink($file_path);
                return $attachment_id;
            }
            
            return $attachment_id;

        } catch (Exception $e) {
            return new WP_Error('image_save_error', $e->getMessage());
        }
    }

    /**
     * Create attachment in media library.
     *
     * @param string $file_path
     * @param int $order_id
     * @return int|WP_Error Attachment ID or error
     */
    private function create_attachment($file_path, $order_id) {
        $filename = basename($file_path);

        $attachment = array(
            'post_mime_type' => 'image/png',
            'post_title'     => sprintf(__('ZATCA QR Code - Order #%d', 'zatca-invoicing'), $order_id),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => wp_upload_dir()['url'] . '/' . $filename
        );

        $attachment_id = wp_insert_attachment($attachment, $file_path, 0, true);
        
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        return $attachment_id;
    }

    /**
     * Save QR code data to database.
     *
     * @param int $order_id
     * @param array $qr_data
     * @param string $invoice_type
     * @return bool
     */
    private function save_qr_code_data($order_id, $qr_data, $invoice_type = 'simplified') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';

        // We only need to store the attachment ID and the raw QR text data
        $data_to_store = [
            'attachment_id' => $qr_data['attachment_id'],
            'qr_code_text'  => $qr_data['qr_code_text'],
        ];

        // Determine submission type based on phase
        $submission_type = $this->settings->is_phase2() ? 'production' : 'phase1';
        
        $data = [
            'order_id' => $order_id,
            'invoice_type' => $invoice_type,
            'submission_type' => $submission_type,
            'environment' => $this->settings->get('zatca_environment', 'sandbox'),
            'status' => 'completed',
            'qr_code' => wp_json_encode($data_to_store),
            'created_at' => current_time('mysql'),
        ];

        // Check if a record for this order, submission type, and invoice type already exists
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE order_id = %d AND submission_type = %s AND invoice_type = %s",
            $order_id,
            $submission_type,
            $invoice_type
        ));

        if ($existing_id) {
            // Update existing record - only update QR-related fields, preserve other data
            $update_data = [
                'qr_code' => wp_json_encode($data_to_store),
                'status' => $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$table_name} WHERE id = %d",
                    $existing_id
                )) ?: 'completed' // Keep existing status or default to completed
            ];
            $result = $wpdb->update(
                $table_name,
                $update_data,
                ['id' => $existing_id]
            );
        } else {
            // Insert new record
            $result = $wpdb->insert($table_name, $data);
        }

        if ($result !== false) {
            update_post_meta($order_id, '_zatca_phase1_status', 'completed');
            // Store attachment ID in post meta for easier access
            update_post_meta($order_id, '_zatca_qr_attachment_id', $qr_data['attachment_id']);
        }

        return $result !== false;
    }

    /**
     * Log QR code generation.
     *
     * @param int $order_id
     * @param string $status
     * @param mixed $message
     */
    private function log_qr_generation($order_id, $status, $message) {
        if ($this->settings->is_debug()) {
            $log_message = sprintf(
                '[ZATCA Phase 1] Order #%d - %s: %s',
                $order_id,
                strtoupper($status),
                is_string($message) ? $message : wp_json_encode($message)
            );
            
            error_log($log_message);
        }
    }

    /**
     * Get QR code data for an order.
     *
     * @param int $order_id
     * @param string|null $invoice_type Optional. Invoice type to check for (e.g., 'simplified', 'simplified-credit').
     * @return array|null
     */
    public function get_qr_code_data($order_id, $invoice_type = 'simplified') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT qr_code, invoice_type FROM $table_name WHERE order_id = %d AND invoice_type = %s AND submission_type IN ('phase1', 'production') ORDER BY created_at DESC LIMIT 1",
            $order_id,
            $invoice_type
        ));

        if ($result && !empty($result->qr_code)) {
            $stored_data = json_decode($result->qr_code, true);

            // New structure set in save_qr_code_data: { attachment_id, qr_code_text }
            if (is_array($stored_data)) {
                $attachment_id = isset($stored_data['attachment_id']) ? (int) $stored_data['attachment_id'] : 0;
                $qr_text       = $stored_data['qr_code_text'] ?? ($stored_data['qr_code'] ?? null);
                if (!empty($qr_text)) {
                    $qr_data = [
                        'qr_code_url'   => $attachment_id ? wp_get_attachment_url($attachment_id) : null,
                        'qr_code'       => $qr_text,
                        'attachment_id' => $attachment_id,
                    ];
                    
                    // Include invoice type if available
                    if (isset($result->invoice_type)) {
                        $qr_data['invoice_type'] = $result->invoice_type;
                    }
                    
                    return $qr_data;
                }
            }
        }

        return null;
    }

    /**
     * Delete QR code data for an order.
     *
     * @param int $order_id
     * @return bool
     */
    public function delete_qr_code_data($order_id) {
        global $wpdb;

        // Get existing data first to clean up files
        $qr_data = $this->get_qr_code_data($order_id);
        
        if ($qr_data && isset($qr_data['attachment_id'])) {
            // This single function deletes the attachment, its file, and any associated thumbnails.
            wp_delete_attachment($qr_data['attachment_id'], true);
        }

        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';
        
        $result = $wpdb->delete(
            $table_name,
            array('order_id' => $order_id, 'submission_type' => 'phase1'),
            array('%d', '%s')
        );

        // Clean up order meta
        delete_post_meta($order_id, '_zatca_phase1_status');
        delete_post_meta($order_id, '_zatca_qr_attachment_id');

        return $result !== false;
    }
}