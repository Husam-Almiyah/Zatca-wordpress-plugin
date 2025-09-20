<?php
/**
 * ZATCA QR Code Generator Class
 *
 * PHP 7.4 compatible implementation for generating ZATCA compliant QR codes
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA QR Code Generator
 */
class ZATCA_QR_Generator {

    /**
     * Generate TLV formatted QR code data
     *
     * @param array $tags Array of tag data
     * @return string Base64 encoded QR data
     */
    public static function generate_tlv_qr($tags) {
        $tlv_data = '';
        
        foreach ($tags as $tag => $value) {
            $tlv_data .= self::build_tlv_tag($tag, $value);
        }
        
        return base64_encode($tlv_data);
    }

    /**
     * Generate QR code for Phase 1 (Simplified Invoice)
     *
     * @param string $seller_name
     * @param string $vat_number
     * @param string $invoice_date (ISO 8601 format)
     * @param string $total_amount
     * @param string $tax_amount
     * @return string Base64 encoded QR data
     */
    public static function generate_phase1_qr($seller_name, $vat_number, $invoice_date, $total_amount, $tax_amount) {
        $tags = array(
            1 => $seller_name,
            2 => $vat_number,
            3 => $invoice_date,
            4 => $total_amount,
            5 => $tax_amount
        );
        
        return self::generate_tlv_qr($tags);
    }

    /**
     * Generate QR code for Phase 2 (Standard Invoice)
     *
     * @param string $seller_name
     * @param string $vat_number  
     * @param string $invoice_date
     * @param string $total_amount
     * @param string $tax_amount
     * @param string $invoice_hash
     * @param string $digital_signature
     * @param string $public_key
     * @param string $certificate_signature
     * @return string Base64 encoded QR data
     */
    public static function generate_phase2_qr($seller_name, $vat_number, $invoice_date, $total_amount, $tax_amount, $invoice_hash, $digital_signature, $public_key, $certificate_signature) {
        $tags = array(
            1 => $seller_name,
            2 => $vat_number,
            3 => $invoice_date,
            4 => $total_amount,
            5 => $tax_amount,
            6 => $invoice_hash,
            7 => $digital_signature,
            8 => $public_key,
            9 => $certificate_signature
        );
        
        return self::generate_tlv_qr($tags);
    }

    /**
     * Build TLV (Tag-Length-Value) formatted data
     *
     * @param int $tag
     * @param string $value
     * @return string
     */
    private static function build_tlv_tag($tag, $value) {
        $value_bytes = $value;
        $length = strlen($value_bytes);
        
        return chr($tag) . chr($length) . $value_bytes;
    }

    /**
     * Generate QR code image from data
     *
     * @param string $data QR code data
     * @param int $size Image size in pixels
     * @return string Base64 encoded PNG image
     */
    public static function generate_qr_image($data, $size = 200) {
        try {
            // Check if chillerlan/php-qrcode is available
            if (class_exists('\chillerlan\QRCode\QRCode')) {
                $qrcode = new \chillerlan\QRCode\QRCode();
                return $qrcode->render($data);
            }
            
            // Fallback to endroid/qr-code if available
            if (class_exists('\Endroid\QrCode\QrCode')) {
                $qrCode = new \Endroid\QrCode\QrCode($data);
                $qrCode->setSize($size);
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $result = $writer->write($qrCode);
                return 'data:image/png;base64,' . base64_encode($result->getString());
            }
            
            // If no QR libraries available, return data URL for manual QR generation
            return self::generate_fallback_qr_url($data);
            
        } catch (Exception $e) {
            error_log('ZATCA QR Image Generation Error: ' . $e->getMessage());
            return self::generate_fallback_qr_url($data);
        }
    }

    /**
     * Generate fallback QR code URL using external service
     *
     * @param string $data
     * @return string
     */
    private static function generate_fallback_qr_url($data) {
        $encoded_data = urlencode($data);
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$encoded_data}";
    }

    /**
     * Validate QR code data format
     *
     * @param string $data Base64 encoded QR data
     * @return bool
     */
    public static function validate_qr_data($data) {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        
        // Basic TLV validation
        $offset = 0;
        $length = strlen($decoded);
        
        while ($offset < $length) {
            if ($offset + 2 > $length) {
                return false;
            }
            
            $tag = ord($decoded[$offset]);
            $value_length = ord($decoded[$offset + 1]);
            
            if ($offset + 2 + $value_length > $length) {
                return false;
            }
            
            $offset += 2 + $value_length;
        }
        
        return true;
    }

    /**
     * Parse QR code data
     *
     * @param string $data Base64 encoded QR data
     * @return array|false Parsed data or false on error
     */
    public static function parse_qr_data($data) {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        
        $result = array();
        $offset = 0;
        $length = strlen($decoded);
        
        while ($offset < $length) {
            if ($offset + 2 > $length) {
                break;
            }
            
            $tag = ord($decoded[$offset]);
            $value_length = ord($decoded[$offset + 1]);
            
            if ($offset + 2 + $value_length > $length) {
                break;
            }
            
            $value = substr($decoded, $offset + 2, $value_length);
            $result[$tag] = $value;
            
            $offset += 2 + $value_length;
        }
        
        return $result;
    }
} 