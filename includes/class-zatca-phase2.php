<?php
/**
 * ZATCA Phase 2 Handler Class
 *
 * Handles Phase 2 implementation - XML Invoice generation and ZATCA API integration
 *
 * @package ZATCA_EInvoicing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ZATCA Phase 2 Class
 */
use Famcare\ZatcaInvoicing\Models\Invoice;
use Famcare\ZatcaInvoicing\Models\InvoiceLine;
use Famcare\ZatcaInvoicing\Models\Party;
use Famcare\ZatcaInvoicing\Models\TaxTotal;
use Famcare\ZatcaInvoicing\Models\Address;
use Famcare\ZatcaInvoicing\Models\TaxScheme;
use Famcare\ZatcaInvoicing\Models\LegalMonetaryTotal;
use Famcare\ZatcaInvoicing\Models\TaxSubTotal;
use Famcare\ZatcaInvoicing\Models\Item;
use Famcare\ZatcaInvoicing\Models\Price;
use Famcare\ZatcaInvoicing\Models\Delivery;
use Famcare\ZatcaInvoicing\Models\InvoiceTypeCode;
use Famcare\ZatcaInvoicing\Models\AdditionalDocumentReference;
use Famcare\ZatcaInvoicing\Models\ClassifiedTaxCategory;
use Famcare\ZatcaInvoicing\XML\XMLGenerator;
use Famcare\ZatcaInvoicing\Signature\InvoiceSigner;
use Famcare\ZatcaInvoicing\Models\InvoiceType;
use Famcare\ZatcaInvoicing\Models\LegalEntity;
use Famcare\ZatcaInvoicing\Models\TaxCategory;
use Famcare\ZatcaInvoicing\Helpers\Certificate;
use Famcare\ZatcaInvoicing\Models\Attachment;
use Famcare\ZatcaInvoicing\Models\PartyTaxScheme;
use Famcare\ZatcaInvoicing\Models\BillingReference;
use Famcare\ZatcaInvoicing\Models\PaymentMeans;
use Famcare\ZatcaInvoicing\Models\AllowanceCharge;

class ZATCA_Phase2 {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Settings instance
     */
    private $settings;

    /**
     * Certificate manager
     */
    private $certificate_manager;

    /**
     * Main ZATCA_Phase2 Instance.
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
     * Initialize Phase 2 functionality.
     */
    private function init() {
        // Load required libraries
        $this->load_dependencies();
        
        // Initialize certificate manager
        $this->certificate_manager = ZATCA_Certificate_Manager::instance();
        
        // Setup hooks
        add_action('zatca_generate_xml_invoice', array($this, 'generate_xml_invoice'), 10, 2);
        add_action('zatca_submit_invoice', array($this, 'submit_invoice_to_zatca'), 10, 1);
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
     * Generate XML invoice for an order.
     *
     * @param int $order_id WooCommerce order ID
     * @param array $options Generation options
     * @return array|WP_Error XML invoice data or error
     */
    public function generate_xml_invoice($order_id, $invoice_type = 'simplified', $options = array()) {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('invalid_order', __('Invalid order ID provided.', 'zatca-invoicing'));
            }

            // ZATCA does not accept invoices with zero charges (free products or 100% discount)
            if ((float)$order->get_total() <= 0) {
                return new WP_Error('zero_amount_invoice', __('ZATCA does not accept invoices with zero or negative amounts. Please ensure the order has a positive total value.', 'zatca-invoicing'));
            }

            $certificate_type = $options['certificate_type'] ?? 'onboarding';

            // 1. Map WC_Order to our Invoice data model
            $invoice = $this->map_order_to_invoice_model($order, $invoice_type);

            // 2. Generate the XML using XMLGenerator
            $generator = XMLGenerator::invoice($invoice);
            $unsignedXml = $generator->getXML();

            $doc = new \DOMDocument();
            $doc->loadXML($unsignedXml);

            // 3. Sign the XML using InvoiceSigner
            $active_certificate_data = $this->certificate_manager->get_active_certificate(null, $certificate_type);
            if (empty($active_certificate_data['private_key']) || empty($active_certificate_data['certificate'])) {
                return new WP_Error('missing_credentials', __('Missing private key or certificate data for signing.', 'zatca-invoicing'));
            }

            $privateKey = $active_certificate_data['private_key'];
            $certificate_data = $active_certificate_data['certificate'];
            $secret = $active_certificate_data['secret'];

            $certificate_obj = new Certificate($certificate_data, $privateKey, $secret);

            $signedInvoiceResult = InvoiceSigner::signInvoice($unsignedXml, $certificate_obj);
            $signedXml = $signedInvoiceResult->getXML();
            $invoiceHash = $signedInvoiceResult->getHash();
            $qrCodeData = $signedInvoiceResult->getQRCode();

            // 4. Save the result
            $result = [
                'xml' => $signedXml,
                'hash' => $invoiceHash,
                'uuid' => $invoice->getUuid(),
                'qr_code' => $qrCodeData
            ];

            $this->save_zatca_invoice_data($order_id, $result, $invoice_type, $certificate_type);
            $this->log_xml_generation($order_id, 'success', $result);

            return $result;

        } catch (Exception $e) {
            $error = new WP_Error('xml_generation_failed', $e->getMessage());
            $this->log_xml_generation($order_id, 'error', $e->getMessage());
            return $error;
        }
    }

    /**
     * Submit invoice to ZATCA.
     *
     * @param int $order_id WooCommerce order ID
     * @param string $invoice_type Invoice type (standard, simplified, debit, credit)
     * @return array|WP_Error Submission result or error
     */
    public function submit_invoice_to_zatca($order_id, $invoice_type = 'simplified', $certificate_type = 'production') {
        try {
            // Get XML invoice data for the specific certificate type
            $invoice_data = $this->get_xml_invoice_data($order_id, $certificate_type, $invoice_type);
            if (is_wp_error($invoice_data) || !$invoice_data) {
                return new WP_Error('no_xml_data', __('No XML invoice data found for this order.', 'zatca-invoicing'));
            }

            // Prepare submission data
            $submission_data = $this->prepare_submission_data($invoice_data);

            // Determine if this is a clearance document (standard, debit, credit) or reporting document (simplified)
            $parts = explode('-', $invoice_type);
            $base_type = $parts[0]; // 'simplified' or 'standard'
            $is_clearance_document = ($base_type === 'standard');

            // Get current environment
            $environment = $this->settings->get('zatca_environment', 'sandbox');

            // Submit to appropriate ZATCA API endpoint based on invoice type
            if ($is_clearance_document) {
                // Use clearance endpoint for standard, debit, and credit invoices
                $response = ZATCA_API_Manager::submit_invoice_clearance($submission_data, $environment);
            } else {
                // Use reporting endpoint for simplified invoices
                $response = ZATCA_API_Manager::submit_invoice_reporting($submission_data, $environment);
            }

            if (is_wp_error($response)) {
                $errorData = $response->get_error_data();
                $body = is_array($errorData) ? ($errorData['response'] ? $errorData['response'] : []) : [];
            } else {
                $body = $response;
            }

            // Process response with the correct certificate type
            if (!empty($body)) {
                $result = $this->process_zatca_response($order_id, $body, $certificate_type, $invoice_type);
            }
            if (!empty($body['validationResults'])) {
                update_post_meta($order_id, '_zatca_validation_results', wp_json_encode($body['validationResults']));
            }

            if (is_wp_error($response)) {
                return $response;
            }

            // Log submission
            $this->log_zatca_submission($order_id, 'success', $response);

            return $result;

        } catch (Exception $e) {
            $error = new WP_Error('submission_failed', $e->getMessage());
            $this->log_zatca_submission($order_id, 'error', $e->getMessage());
            return $error;
        }
    }

    /**
     * Prepare invoice data from WooCommerce order.
     *
     * @param WC_Order $order
     * @param array $options
     * @return Invoice
     */
    private function map_order_to_invoice_model(WC_Order $order, $invoice_type = 'simplified'): Invoice
    {
        // Instantiate invoice
        $invoice = new Invoice();

        // --- Core invoice details ---
        $invoice->setId($order->get_order_number());
        $invoice->setUUID(wp_generate_uuid4());

        $parts = explode('-', $invoice_type);
        $invoiceCategory = $parts[0]; // 'simplified' or 'standard'

        // Use current time for simplified invoices to avoid BR-KSA-98
        $invoice_date = $invoiceCategory === 'simplified'
            ? new \DateTime()
            : new \DateTime($order->get_date_created()->format('Y-m-d H:i:s'));

        $invoice->setIssueDate(new \DateTime($invoice_date->format('Y-m-d')));
        // keep your existing time format
        $invoice->setIssueTime(new \DateTime($invoice_date->format('H:i:s.v\Z')));

        // --- Invoice Type ---
        $invoiceTypeObject = new InvoiceType();
        $this->setInvoiceTypeFromString($invoiceTypeObject, $invoice_type);
        $invoice->setInvoiceType($invoiceTypeObject);

        // Handle credit/debit notes
        $parts = explode('-', $invoice_type);
        $document_type = isset($parts[1]) ? $parts[1] : 'invoice';

        if ($document_type === 'credit' || $document_type === 'debit') {
            $reason = $this->getInvoiceReason($order, $document_type);
            $invoice->setNote($reason);

            $original_invoice_id = $this->getOriginalInvoiceId($order, $document_type);
            if ($original_invoice_id) {
                $billingRef = new BillingReference();
                $billingRef->setId($original_invoice_id);
                $invoice->setBillingReferences([$billingRef]);
            }

            $paymentMeans = new PaymentMeans();
            $paymentMeans->setPaymentMeansCode('10'); // Cash (common)
            $paymentMeans->setInstructionNote($reason);
            $invoice->setPaymentMeans($paymentMeans);
        }

        // --- Additional Document References (ICV, PIH) ---
        $additionalRefs = [];

        $icvRef = new AdditionalDocumentReference();
        $icvRef->setId('ICV');
        $icvRef->setUUID((string)$order->get_id());
        $additionalRefs[] = $icvRef;

        $previousHash = $this->get_previous_invoice_hash($order->get_id());
        if (!$previousHash) {
            // Default PIH as per ZATCA examples for first invoice
            $previousHash = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
        }

        $attachment = new Attachment();
        $attachment->setBase64Content($previousHash, 'PIH', 'text/plain');

        $pihRef = new AdditionalDocumentReference();
        $pihRef->setId('PIH');
        $pihRef->setAttachment($attachment);
        $additionalRefs[] = $pihRef;

        $invoice->setAdditionalDocumentReferences($additionalRefs);

        // --- Seller Party ---
        $settings = $this->settings->get_settings();
        $sellerParty = new Party();
        $sellerVatNumber = $settings['zatca_vat_number'];

        if (!empty($sellerVatNumber)) {
            $sellerParty->setPartyIdentification($sellerVatNumber);
            $sellerParty->setPartyIdentificationId('CRN'); // preferred
        }
        $sellerParty->setLegalEntity((new LegalEntity())->setRegistrationName($settings['zatca_seller_name']));

        // Validate required address fields
        $required_address_fields = [
            'zatca_street_name'     => 'Street Name',
            'zatca_building_number' => 'Building Number',
            'zatca_district'        => 'District',
            'zatca_city'            => 'City',
            'zatca_postal_code'     => 'Postal Code',
        ];
        foreach ($required_address_fields as $field_key => $field_label) {
            if (empty($settings[$field_key])) {
                throw new \InvalidArgumentException(sprintf(__('Seller %s cannot be empty. Please configure it in ZATCA E-Invoicing settings.', 'zatca-invoicing'), $field_label));
            }
        }

        $sellerAddress = new Address();
        $sellerAddress->setStreetName($settings['zatca_street_name']);
        $building_number = isset($settings['zatca_building_number']) ? (string)$settings['zatca_building_number'] : '0000';
        $sellerAddress->setBuildingNumber(str_pad($building_number, 4, '0', STR_PAD_LEFT)); // BR-KSA-37
        $sellerAddress->setPlotIdentification($settings['zatca_plot_identification'] ?: '');
        $sellerAddress->setCitySubdivisionName($settings['zatca_district']);
        $sellerAddress->setCityName($settings['zatca_city']);
        $sellerAddress->setPostalZone($settings['zatca_postal_code']);
        $sellerAddress->setCountry('SA');
        $sellerParty->setPostalAddress($sellerAddress);

        $sellerTaxScheme = new PartyTaxScheme();
        if (!empty($sellerVatNumber)) {
            $sellerTaxScheme->setCompanyId($sellerVatNumber);
        }
        $sellerTaxScheme->setTaxScheme((new TaxScheme())->setId('VAT'));
        $sellerParty->setPartyTaxScheme($sellerTaxScheme);
        $invoice->setAccountingSupplierParty($sellerParty);

        // --- Customer Party ---
        $customerParty = new Party();
        $customerVatNumber = $order->get_meta('_billing_vat_number');
        if (!empty($customerVatNumber)) {
            $customerParty->setPartyIdentification($customerVatNumber);
            $customerParty->setPartyIdentificationId('CRN');
        }
        $customerParty->setLegalEntity(
            (new LegalEntity())->setRegistrationName(
                $order->get_billing_company() ?: $order->get_formatted_billing_full_name()
            )
        );

        $customerAddress = new Address();
        $customerAddress->setStreetName($order->get_billing_address_1() ?: 'Not Available');
        $customer_building = $order->get_meta('_billing_building_number') ?: '0000';
        $customerAddress->setBuildingNumber(str_pad((string)$customer_building, 4, '0', STR_PAD_LEFT));
        $customerAddress->setPlotIdentification($order->get_meta('_billing_plot_identification') ?: '');
        $customerAddress->setCitySubdivisionName($order->get_billing_address_2() ?: 'Not Available');
        $customerAddress->setCityName($order->get_billing_city() ?: 'Not Available');
        $customerAddress->setPostalZone($order->get_billing_postcode() ?: '00000');
        $customerAddress->setCountry('SA');
        $customerParty->setPostalAddress($customerAddress);

        $customerTaxScheme = new PartyTaxScheme();
        if (!empty($customerVatNumber)) {
            $customerTaxScheme->setCompanyId($customerVatNumber);
        }
        $customerTaxScheme->setTaxScheme((new TaxScheme())->setId('VAT'));
        $customerParty->setPartyTaxScheme($customerTaxScheme);
        $invoice->setAccountingCustomerParty($customerParty);

        // --- Delivery ---
        $delivery = new Delivery();
        $delivery->setActualDeliveryDate($order->get_date_completed() ? $order->get_date_completed()->format('Y-m-d') : date('Y-m-d'));
        $invoice->setDelivery($delivery);

        // --- Gather line data & build invoice lines ---
        $invoiceLines = [];
        $taxSubTotals = []; // per rate: ['taxableAmount' => x, 'taxAmount' => y, 'percent' => p]
        $subtotal_without_discount = 0.00; // sum of BT-131
        $vat_total_before_discount = 0.00;

        foreach ($order->get_items('line_item') as $item_id => $item) {
            /** @var \WC_Order_Item_Product $item */
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;

            $line = new InvoiceLine();
            $line->setId($item_id);
            $qty = (float)$item->get_quantity();
            $qty = $qty > 0 ? $qty : 1.0;
            $line->setInvoicedQuantity($qty);

            $line_net = (float)$item->get_subtotal();       // BT-131 (net of line-level discounts, before doc-level allowance)
            $line_vat = (float)$item->get_subtotal_tax();   // VAT on that net
            $subtotal_without_discount += $line_net;
            $vat_total_before_discount += $line_vat;

            $line->setLineExtensionAmount($line_net);

            $lineItem = new Item();
            $lineItem->setName($item->get_name());

            $tax_rates = WC_Tax::get_rates($item->get_tax_class());
            $tax_rate = reset($tax_rates);
            $percent = $tax_rate ? (float)$tax_rate['rate'] : 15.00;

            $taxCategory = new ClassifiedTaxCategory();
            $taxCategory->setPercent($percent);
            $taxCategory->setTaxScheme((new TaxScheme())->setId('VAT'));
            $lineItem->setClassifiedTaxCategory($taxCategory);

            $price = new Price();
            $price->setPriceAmount($line_net / $qty);
            $line->setPrice($price);

            // Store VAT-inclusive amount in metadata (as in your original flow)
            $lineAmountWithVat = $line_net + $line_vat;
            update_post_meta($order->get_id(), "_line_{$item_id}_amount_with_vat", $lineAmountWithVat);

            $line->setItem($lineItem);
            $invoiceLines[] = $line;

            // Accumulate per-rate buckets (pre-discount)
            $key = (string)$percent;
            if (!isset($taxSubTotals[$key])) {
                $taxSubTotals[$key] = ['taxableAmount' => 0.00, 'taxAmount' => 0.00, 'percent' => $percent];
            }
            $taxSubTotals[$key]['taxableAmount'] += $line_net;
            $taxSubTotals[$key]['taxAmount']     += $line_vat;
        }
        $invoice->setInvoiceLines($invoiceLines);

        // --- Document-level allowances/charges (Discounts) ---
        // Start from Woo order discounts (document-level concept)
        $discount_total = (float)$order->get_discount_total();

        // Debit notes: ignore allowance
        if ($document_type === 'debit') {
            $discount_total = 0.0;
        }
        // Credit notes: ensure non-negative allowance
        if ($document_type === 'credit' && $discount_total < 0) {
            $discount_total = 0.0;
        }

        // If there were no line items with tax info, create a default bucket (avoid BR-CO-18)
        if (empty($taxSubTotals)) {
            $taxSubTotals['15'] = [
                'taxableAmount' => 0.00,
                'taxAmount'     => 0.00,
                'percent'       => 15.0,
            ];
        }

        // Distribute the document-level discount proportionally across rate buckets
        $distributedDiscountByRate = []; // percent => base discount (2dp)
        $totalTaxableBase = 0.00;
        foreach ($taxSubTotals as $b) {
            $totalTaxableBase += $b['taxableAmount'];
        }

        if ($discount_total > 0.0) {
            // First pass: proportional shares (unrounded)
            $rawShares = [];
            foreach ($taxSubTotals as $k => $b) {
                $share = ($totalTaxableBase > 0) ? ($discount_total * ($b['taxableAmount'] / $totalTaxableBase)) : 0.0;
                $rawShares[$k] = $share;
            }
            // Round to 2dp and reconcile residual to the bucket with the largest taxable base
            $roundedShares = [];
            $sumRounded = 0.00;
            $maxKey = array_key_first($taxSubTotals);
            $maxBase = -INF;
            foreach ($taxSubTotals as $k => $b) {
                $roundedShares[$k] = round($rawShares[$k], 2);
                $sumRounded += $roundedShares[$k];
                if ($b['taxableAmount'] > $maxBase) {
                    $maxBase = $b['taxableAmount'];
                    $maxKey  = $k;
                }
            }
            $residual = round($discount_total - $sumRounded, 2);
            if (abs($residual) >= 0.01) {
                $roundedShares[$maxKey] = round($roundedShares[$maxKey] + $residual, 2);
            }
            $distributedDiscountByRate = $roundedShares;
        }

        // Apply distributed discount per rate and recompute per-rate VAT (post-discount)
        $adjustedTaxSubTotals = []; // same structure, but after discount
        foreach ($taxSubTotals as $k => $b) {
            $percent = (float)$b['percent'];
            $rate = $percent / 100.0;

            $discBase = isset($distributedDiscountByRate[$k]) ? $distributedDiscountByRate[$k] : 0.00;

            $newTaxable = round($b['taxableAmount'] - $discBase, 2);
            if ($newTaxable < 0) $newTaxable = 0.00;

            // Recalculate VAT on adjusted base (2dp)
            $newVat = round($newTaxable * $rate, 2);

            $adjustedTaxSubTotals[$k] = [
                'taxableAmount' => $newTaxable,
                'taxAmount'     => $newVat,
                'percent'       => $percent,
            ];
        }

        // Reconcile rounding: ensure sums match LMT expectations exactly
        $expected_tax_exclusive = round($subtotal_without_discount - $discount_total, 2);
        $sum_adj_taxable = 0.00;
        foreach ($adjustedTaxSubTotals as $b) { $sum_adj_taxable += $b['taxableAmount']; }
        $sum_adj_taxable = round($sum_adj_taxable, 2);

        $delta_base = round($expected_tax_exclusive - $sum_adj_taxable, 2);
        if (abs($delta_base) >= 0.01) {
            // add the tiny rounding delta to the bucket with the largest taxable
            $maxKey = array_key_first($adjustedTaxSubTotals);
            $maxBase = -INF;
            foreach ($adjustedTaxSubTotals as $k => $b) {
                if ($b['taxableAmount'] > $maxBase) { $maxBase = $b['taxableAmount']; $maxKey = $k; }
            }
            $adjustedTaxSubTotals[$maxKey]['taxableAmount'] = round($adjustedTaxSubTotals[$maxKey]['taxableAmount'] + $delta_base, 2);
            // recompute its VAT after base tweak
            $p = $adjustedTaxSubTotals[$maxKey]['percent'] / 100.0;
            $adjustedTaxSubTotals[$maxKey]['taxAmount'] = round($adjustedTaxSubTotals[$maxKey]['taxableAmount'] * $p, 2);
        }

        // Now compute final totals post-discount
        $tax_exclusive = 0.00;
        $tax_total     = 0.00;
        foreach ($adjustedTaxSubTotals as $b) {
            $tax_exclusive += $b['taxableAmount'];
            $tax_total     += $b['taxAmount'];
        }
        $tax_exclusive = round($tax_exclusive, 2);
        $tax_total     = round($tax_total, 2);
        $tax_inclusive = round($tax_exclusive + $tax_total, 2);

        // --- Legal Monetary Total (consistent with adjusted buckets) ---
        $lmt = new LegalMonetaryTotal();
        $lmt->setLineExtensionAmount(round($subtotal_without_discount, 2)); // BT-106: sum of line nets pre-doc-discount
        $lmt->setTaxExclusiveAmount($tax_exclusive);                        // after doc discount
        $lmt->setTaxInclusiveAmount($tax_inclusive);                        // = exclusive + VAT
        $lmt->setAllowanceTotalAmount(round($discount_total, 2));
        $lmt->setPrepaidAmount(0.00);
        $lmt->setPayableAmount($tax_inclusive); // equals TaxInclusiveAmount
        $invoice->setLegalMonetaryTotal($lmt);

        // --- Build AllowanceCharge list (one per tax rate, proportional share) ---
        $allowanceCharges = [];
        if ($discount_total > 0.0) {
            // Reason depends on document type
            $allowanceReason = ($document_type === 'credit') ? 'Credit adjustment' : 'Discount';

            foreach ($taxSubTotals as $k => $origBucket) {
                $baseShare = isset($distributedDiscountByRate[$k]) ? $distributedDiscountByRate[$k] : 0.00;
                if ($baseShare <= 0.0) continue;

                $allowance = new AllowanceCharge();
                $allowance->setChargeIndicator(false); // allowance
                $allowance->setAllowanceChargeReason($allowanceReason);
                $allowance->setAmount(round($baseShare, 2));

                $percent = (float)$origBucket['percent'];
                $allowanceTaxCategory = new TaxCategory();
                $allowanceTaxCategory->setPercent($percent);

                // Map to KSA categories
                if ($percent >= 15.0) {
                    $allowanceTaxCategory->setId('S');   // Standard
                } elseif ($percent > 0.0) {
                    $allowanceTaxCategory->setId('AA');  // Reduced
                } else {
                    $allowanceTaxCategory->setId('Z');   // Zero rated
                }
                $allowanceTaxCategory->setTaxScheme((new TaxScheme())->setId('VAT'));
                $allowance->setTaxCategory($allowanceTaxCategory);

                $allowanceCharges[] = $allowance;
            }
        }
        if (!empty($allowanceCharges)) {
            $invoice->setAllowanceCharges($allowanceCharges);
        }

        // --- TaxTotal (two blocks: summary + breakdown) ---
        $firstTaxTotal = new TaxTotal();
        $firstTaxTotal->setTaxAmount($tax_total);

        $secondTaxTotal = new TaxTotal();
        $secondTaxTotal->setTaxAmount($tax_total);

        foreach ($adjustedTaxSubTotals as $b) {
            // Skip empty buckets after adjustment
            if ($b['taxableAmount'] <= 0 && $b['taxAmount'] <= 0) {
                continue;
            }
            $subTotal = new TaxSubTotal();
            $subTotal->setTaxableAmount(round($b['taxableAmount'], 2));
            $subTotal->setTaxAmount(round($b['taxAmount'], 2));

            $category = new TaxCategory();
            $category->setPercent((float)$b['percent']);
            if ($b['percent'] >= 15.0) {
                $category->setId('S');
            } elseif ($b['percent'] > 0.0) {
                $category->setId('AA');
            } else {
                $category->setId('Z');
            }
            $category->setTaxScheme((new TaxScheme())->setId('VAT'));
            $subTotal->setTaxCategory($category);

            $secondTaxTotal->addTaxSubTotal($subTotal);
        }

        $invoice->setTaxTotals([$firstTaxTotal, $secondTaxTotal]);

        return $invoice;
    }


    private function prepare_submission_data($invoice_data)
    {
        if (empty($invoice_data['xml']) || empty($invoice_data['hash'])) {
            return new WP_Error('missing_invoice_data', __('Missing XML or hash for submission.', 'zatca-invoicing'));
        }

        return [
            'invoiceHash' => $invoice_data['hash'],
            'invoice' => base64_encode($invoice_data['xml']),
            'uuid' => $invoice_data['uuid']
        ];
    }

    public function call_zatca_api($endpoint, $data, $method = 'POST', $certificate_type = 'production')
    {
        $base_url = $this->settings->get_api_base_url();
        $url = $base_url . $endpoint;

        $environment = $this->settings->get('zatca_environment', 'sandbox');
        
        // Get the appropriate certificate based on type
        $certificate = $this->certificate_manager->get_active_certificate($environment, $certificate_type);
        if (is_wp_error($certificate)) {
            return $certificate;
        }

        $binary_security_token = $certificate['binary_security_token'];
        $secret = $certificate['secret'];

        if (empty($binary_security_token) || empty($secret)) {
            return new WP_Error('missing_credentials', sprintf(__('Missing binary security token or secret for %s certificate in %s environment.', 'zatca-invoicing'), $certificate_type, $environment));
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
            'Accept-Version' => $this->settings->get_simulation_accept_version()
        ];

        $auth_headers = $this->create_auth_headers( $binary_security_token, $secret);

        $headers = array_merge($headers, $auth_headers);

        $args = [
            'method' => $method,
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => (int) $this->settings->get('zatca_api_timeout', 45)
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            if ($this->settings->is_debug()) {
                error_log('ZATCA WP_Error: ' . $response->get_error_message());
            }
            return $response;
        }

        return $response;
    }

    private function create_auth_headers(string $binary_security_token, string $secret): array
    {
        // API expects Authorization: Basic base64(base64(cert or BST):secret)
        $clean_token = trim($binary_security_token);
        $left = $clean_token;
        // If token contains PEM markers, base64-encode it first
        if (strpos($clean_token, '-----BEGIN') !== false) {
            $left = base64_encode($clean_token);
        }
        $credentials = base64_encode($left . ':' . $secret);
        return ['Authorization' => 'Basic ' . $credentials];
    }

    private function process_zatca_response($order_id, $response, $certificate_type = 'production', $invoice_type = 'simplified')
    {
        $status = 'failed';
        $errors = [];

        if (isset($response['clearanceStatus']) && $response['clearanceStatus'] === 'CLEARED') {
            $status = 'cleared';
        } elseif (isset($response['reportingStatus']) && $response['reportingStatus'] === 'REPORTED') {
            $status = 'reported';
        } elseif (isset($response['validationResults']['status']) && $response['validationResults']['status'] === 'ERROR') {
            $status = 'failed';
            if (isset($response['validationResults']['errors'])) {
                foreach ($response['validationResults']['errors'] as $error) {
                    $errors[] = $error['message'] . ' (Code: ' . $error['code'] . ')';
                }
            }
        } else {
            // Generic error or unexpected response
            $status = 'failed';
            $errors[] = __('An unexpected error occurred during ZATCA API communication.', 'zatca-invoicing');
            if (isset($response['message'])) {
                $errors[] = $response['message'];
            }
        }

        $result = [
            'status' => $status,
            'response' => $response,
            'errors' => $errors
        ];

        // Also save the current invoice hash for use as PIH in next invoice
        $xml_invoice_data = $this->get_xml_invoice_data($order_id, $certificate_type);
        if (!is_wp_error($xml_invoice_data) && !empty($xml_invoice_data['hash'])) {
            $result['hash'] = $xml_invoice_data['hash'];
        }

        // Save with the correct certificate type
        $this->save_zatca_invoice_data($order_id, $result, $invoice_type, $certificate_type);

        // If successful, proceed with PDF/A-3 embedding
        if ($status === 'cleared' || $status === 'reported') {
            $invoice_data = $this->get_xml_invoice_data($order_id, $certificate_type);
            if (!is_wp_error($invoice_data) && $invoice_data) {
                $qr_code_data = $invoice_data['qr_code'] ?? '';
                $this->embed_pda3_to_pdf($order_id, $invoice_data['xml'], $qr_code_data);
            }
        }

        return $result;
    }

    /**
     * Embeds ZATCA XML and QR code into a PDF/A-3 compliant invoice.
     *
     * This method is a placeholder for integrating with a PDF generation library
     * that supports PDF/A-3 compliance. Due to the complexity and dependency on
     * external libraries (like TCPDF, mPDF, FPDF with PDF/A-3 extensions),
     * the actual implementation needs to be done by the user or by integrating
     * a dedicated PDF plugin.
     *
     * The process generally involves:
     * 1. **Generating the Human-Readable Invoice PDF:** If your WooCommerce setup
     *    doesn't already generate a PDF invoice, you'll need to use a PDF generation
     *    library or a WooCommerce PDF Invoice plugin to create the visual invoice.
     * 2. **Embedding the ZATCA XML:** The `$xml_data` (signed XML) needs to be
     *    embedded as an attachment within the PDF/A-3 document. This is a key
     *    requirement for ZATCA compliance.
     * 3. **Embedding the QR Code:** The `$qr_code_data` (base64 encoded image)
     *    needs to be decoded and embedded visually onto the PDF invoice.
     * 4. **Ensuring PDF/A-3 Compliance:** The generated PDF must adhere to the
     *    PDF/A-3 standard, which includes specific metadata, font embedding,
     *    and other archival properties.
     * 5. **Saving the PDF:** The final PDF/A-3 compliant invoice should be saved
     *    to a designated secure location and its path or URL updated in the
     *    WooCommerce order metadata for future reference.
     *
     * @param int $order_id WooCommerce order ID.
     * @param string $xml_data The signed ZATCA XML content.
     * @param string $qr_code_data The base64 encoded QR code data.
     * @return bool True if the embedding process was initiated (even if a placeholder),
     *              false on critical failure (e.g., missing data).
     */
    private function embed_pda3_to_pdf($order_id, $xml_data, $qr_code_data)
    {
        // Log the attempt for debugging purposes.
        if ($this->settings->is_debug()) {
            error_log(sprintf(
                'ZATCA: PDF/A-3 embedding placeholder triggered for Order #%d. XML size: %d bytes, QR Code size: %d bytes.',
                $order_id,
                strlen($xml_data),
                strlen($qr_code_data)
            ));
        }

        // In a real scenario, you would call your PDF library here.
        // Example (conceptual, requires actual library integration):
        /*
        try {
            $pdf_generator = new YourPdfLibrary();
            $pdf_generator->loadHtml( $this->generate_invoice_html($order_id) ); // Or load existing PDF
            $pdf_generator->addAttachment($xml_data, 'zatca_invoice.xml', 'application/xml');
            $pdf_generator->addImage($qr_code_data, 'qr_code.png', ['x' => 10, 'y' => 10]);
            $pdf_generator->setPdfA3Compliance();
            $pdf_path = $pdf_generator->save('path/to/invoice_order_' . $order_id . '.pdf');

            update_post_meta($order_id, '_zatca_pda3_invoice_path', $pdf_path);
            return true;
        } catch (Exception $e) {
            if ($this->settings->is_debug()) {
                error_log('ZATCA PDF/A-3 Embedding Error: ' . $e->getMessage());
            }
            return false;
        }
        */

        // For now, we'll just save the XML and QR code data to order meta
        // to indicate that the data for embedding is available.
        // update_post_meta($order_id, '_zatca_pda3_xml_data_for_embedding', $xml_data);
        // update_post_meta($order_id, '_zatca_pda3_qr_data_for_embedding', $qr_code_data);

        return true;
    }

    private function log_xml_generation($order_id, $status, $data)
    {
        if ($this->settings->is_debug()) {
            $log_message = sprintf(
                '[ZATCA Phase 2] XML Generation for Order #%d - Status: %s, Data: %s',
                $order_id,
                $status,
                json_encode($data)
            );
            error_log($log_message);
        }
    }



    /**
     * Get XML invoice data by submission type
     *
     * @param int $order_id
     * @param string $submission_type 'onboarding' or 'production'
     * @return array|null
     */
    public function get_xml_invoice_data($order_id, $submission_type = 'production', $invoice_type = 'simplified')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';

        $invoice_data = $wpdb->get_row($wpdb->prepare(
            "SELECT signed_xml, invoice_hash, uuid, status, irn, pih, qr_code, zatca_response, created_at
             FROM $table_name 
             WHERE order_id = %d AND submission_type = %s AND invoice_type = %s
             ORDER BY created_at DESC LIMIT 1",
            $order_id,
            $submission_type,
            $invoice_type
        ), ARRAY_A);

        if (!$invoice_data) {
            return null;
        }

        return [
            'xml' => $invoice_data['signed_xml'],
            'hash' => $invoice_data['invoice_hash'],
            'uuid' => $invoice_data['uuid'],
            'status' => $invoice_data['status'],
            'irn' => $invoice_data['irn'],
            'pih' => $invoice_data['pih'],
            'qr_code' => $invoice_data['qr_code'],
            'zatca_response' => json_decode($invoice_data['zatca_response'], true),
            'created_at' => $invoice_data['created_at'],
        ];
    }

    private function save_zatca_invoice_data($order_id, $data, $invoice_type = 'simplified', $certificate_type = 'onboarding')
    {
        global $wpdb;
        
        // Always use the new zatca_invoice_submissions table
        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';

        $invoice_data = [
            'order_id' => $order_id,
            'invoice_type' => $invoice_type,
            'submission_type' => $certificate_type,
            'environment' => $this->settings->get('zatca_environment', 'sandbox'),
            'submitted_at' => current_time('mysql'),
        ];

        if (isset($data['status']) && !empty($data['status'])) {
            $invoice_data['status'] = $data['status'] ?? 'pending';
        }
        if (isset($data['xml']) && !empty($data['xml'])) {
            $invoice_data['signed_xml'] = $data['xml'] ?? null;
        }
        if (isset($data['uuid']) && !empty($data['uuid'])) {
            $invoice_data['uuid'] = $data['uuid'] ?? null;
        }
        if (isset($data['hash']) && !empty($data['hash'])) {
            $invoice_data['invoice_hash'] = $data['hash'] ?? null;
        }

        // Check if a record for this order, submission type, and invoice type already exists
        $existing_invoice_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE order_id = %d AND submission_type = %s AND invoice_type = %s",
            $order_id,
            $certificate_type,
            $invoice_type
        ));

        if ($existing_invoice_id) {
            // Update existing record
            $result = $wpdb->update(
                $table_name,
                $invoice_data,
                ['id' => $existing_invoice_id]
            );
        } else {
            // Insert new record
            $result = $wpdb->insert($table_name, $invoice_data);
        }
        
    }

    private function log_zatca_submission($order_id, $status, $data)
    {
        if ($this->settings->is_debug()) {
            $log_message = sprintf(
                '[ZATCA Phase 2] API Submission for Order #%d - Status: %s, Data: %s',
                $order_id,
                $status,
                json_encode($data)
            );
            error_log($log_message);
        }
    }

    /**
     * Get previous invoice hash for PIH reference
     */
    private function get_previous_invoice_hash($current_order_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zatca_invoice_submissions';

        // Get the most recent invoice hash before this order (production invoices only)
        $previous_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT invoice_hash FROM $table_name 
             WHERE order_id < %d AND submission_type = 'production' AND invoice_hash IS NOT NULL 
             ORDER BY order_id DESC LIMIT 1",
            $current_order_id
        ));

        return $previous_hash;
    }
    
    /**
     * Set invoice type object from string representation.
     * Handles 'simplified', 'standard', 'simplified-credit', etc.
     *
     * @param InvoiceType $invoiceTypeObject
     * @param string $invoice_type
     */
    private function setInvoiceTypeFromString($invoiceTypeObject, $invoice_type) {
        // Parse the invoice type string
        $parts = explode('-', $invoice_type);
        $category = $parts[0]; // 'simplified' or 'standard'
        $document_type = isset($parts[1]) ? $parts[1] : 'invoice'; // 'invoice', 'credit', 'debit'
        
        // Validate and set category
        switch ($category) {
            case 'simplified':
                $invoiceTypeObject->setInvoice('simplified');
                break;
            case 'standard':
                $invoiceTypeObject->setInvoice('standard');
                break;
            default:
                // Fallback to simplified for unknown categories
                $invoiceTypeObject->setInvoice('simplified');
                $category = 'simplified';
                break;
        }
        
        // Validate and set document type
        switch ($document_type) {
            case 'invoice':
            case 'credit':
            case 'debit':
                $invoiceTypeObject->setInvoiceType($document_type);
                break;
            default:
                // Fallback to invoice for unknown document types
                $invoiceTypeObject->setInvoiceType('invoice');
                break;
        }
    }
    
    /**
     * Get the reason for credit or debit note (KSA-10 requirement).
     *
     * @param WC_Order $order
     * @param string $document_type 'credit' or 'debit'
     * @return string
     */
    private function getInvoiceReason($order, $document_type) {
        // Check for custom reason in order meta
        $custom_reason = $order->get_meta('_refund_reason');
        if (!empty($custom_reason)) {
            return $custom_reason;
        }
        
        // Default reasons based on document type
        if ($document_type === 'credit') {
            if ($order->get_total_refunded() > 0) {
                return 'Partial refund issued for returned items.';
            } elseif ($order->get_status() === 'refunded') {
                return 'Full refund issued for order cancellation.';
            } else {
                return 'Credit note issued for price adjustment.';
            }
        } elseif ($document_type === 'debit') {
            return 'Additional charges applied to original invoice.';
        }
        
        return 'Invoice adjustment required.';
    }
    
    /**
     * Get the original invoice ID for credit/debit notes.
     *
     * @param WC_Order $order
     * @param string $document_type 'credit' or 'debit'
     * @return string|null
     */
    private function getOriginalInvoiceId($order, $document_type) {
        // For refunds, try to get the parent order ID
        if ($document_type === 'credit' && $order->get_parent_id()) {
            $parent_order = wc_get_order($order->get_parent_id());
            if ($parent_order) {
                return $parent_order->get_order_number();
            }
        }
        
        // Fallback: use order number without suffix for credit/debit 
        $order_number = $order->get_order_number();
        
        // Remove credit/debit suffix if present
        $order_number = preg_replace('/-(credit|debit)$/', '', $order_number);
        
        return $order_number;
    }
    
    /**
     * Calculate precise line extension totals for credit/debit notes.
     * This ensures ZATCA calculation compliance.
     *
     * @param WC_Order $order
     * @return array
     */
    private function calculateLineExtensionTotals($order) {
        $lineExtensionAmount = 0.0;
        $taxAmount = 0.0;
        
        foreach ($order->get_items('line_item') as $item) {
            /** @var \WC_Order_Item_Product $item */
            $lineExtensionAmount += (float)$item->get_subtotal();
            $taxAmount += (float)$item->get_total_tax();
        }
        
        return [
            'lineExtensionAmount' => $lineExtensionAmount,
            'taxAmount' => $taxAmount
        ];
    }
}