<?php

namespace Famcare\ZatcaInvoicing\Signature;

use Famcare\ZatcaInvoicing\Exceptions\ZatcaStorageException;
use Famcare\ZatcaInvoicing\Helpers\QRCodeGenerator;
use Famcare\ZatcaInvoicing\Helpers\Certificate;
use Famcare\ZatcaInvoicing\Helpers\InvoiceExtension;
use Famcare\ZatcaInvoicing\Helpers\InvoiceSignatureBuilder;

class InvoiceSigner
{
    private $signedInvoice;  // Signed invoice XML string
    private $hash;           // Invoice hash (base64 encoded)
    private $qrCode;         // QR Code (base64 encoded)
    private $certificate;    // Certificate used for signing
    private $digitalSignature; // Digital signature (base64 encoded)

    // Private constructor to force usage of signInvoice method
    private function __construct() {}

    /**
     * Signs the invoice XML and returns an InvoiceSigner object.
     *
     * @param string      $xmlInvoice  Invoice XML as a string
     * @param Certificate $certificate Certificate for signing
     * @return self
     */
    public static function signInvoice(string $xmlInvoice, Certificate $certificate): self
    {
        $instance = new self();
        $instance->certificate = $certificate;

        // Convert XML string to DOM
        $xmlDom = InvoiceExtension::fromString($xmlInvoice);

        // Remove unwanted tags per guidelines
        $xmlDom->removeByXpath('ext:UBLExtensions');
        $xmlDom->removeByXpath('cac:Signature');
        $xmlDom->removeParentByXpath('cac:AdditionalDocumentReference/cbc:ID[. = "QR"]');

        // Debug: Log XML before hashing
        $xmlForHashing = $xmlDom->getElement()->C14N(false, false);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA Hash Debug - XML length: " . strlen($xmlForHashing));
            error_log("ZATCA Hash Debug - XML snippet: " . substr($xmlForHashing, 0, 200) . "...");
        }
        
        // Log more of the XML structure for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA Hash Debug - XML middle: " . substr($xmlForHashing, strlen($xmlForHashing)/2, 200));
            error_log("ZATCA Hash Debug - XML end: " . substr($xmlForHashing, -200));
        }

        // Compute hash using SHA-256
        $invoiceHashBinary = hash('sha256', $xmlForHashing, true);
        $instance->hash = base64_encode($invoiceHashBinary);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA Hash Debug - Generated hash: " . $instance->hash);
            error_log("ZATCA Hash Debug - Binary hash hex: " . bin2hex($invoiceHashBinary));
        }

        // Create digital signature using the private key
        $privateKey = $certificate->getPrivateKey();
        if (!$privateKey) {
            throw new \Exception('Private key is not available for signing.');
        }

        $instance->digitalSignature = base64_encode(
            $privateKey->sign($invoiceHashBinary)
        );

        // Prepare UBL Extension with certificate, hash, and signature
        $ublExtension = (new InvoiceSignatureBuilder())
            ->setCertificate($certificate)
            ->setInvoiceDigest($instance->hash)
            ->setSignatureValue($instance->digitalSignature)
            ->buildSignatureXml();

        // Format the signature XML to ensure proper indentation
        // $formattedSignature = self::formatSignatureXml($ublExtension);

        // Generate QR Code using the SAME hash that will be submitted
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("ZATCA QR Generation Debug - Using hash: " . $instance->hash);
        }
        $instance->qrCode = QRCodeGenerator::createFromTags(
            $xmlDom->generateQrTagsArray($certificate, $instance->hash, $instance->digitalSignature)
        )->encodeBase64();


        // Insert UBL Extension and QR Code into the XML
        $signedInvoice = str_replace(
            [
                "<cbc:ProfileID>",
                '<cac:AccountingSupplierParty>',
            ],
            [
                "<ext:UBLExtensions>" . $ublExtension . "</ext:UBLExtensions>" . PHP_EOL . "    <cbc:ProfileID>",
                $instance->getQRNode($instance->qrCode) . PHP_EOL . "    <cac:AccountingSupplierParty>",
            ],
            $xmlDom->toXml()
        );

        // Remove extra blank lines and save
        $instance->signedInvoice = preg_replace('/^[ \t]*[\r\n]+/m', '', $signedInvoice);

        // Apply canonical XML formatting to the entire signed XML for consistency
        $instance->signedInvoice = self::formatEntireXml($instance->signedInvoice);

        // Recompute the invoice hash from the FINAL signed XML to ensure API hash matches ZATCA calculation
        try {
            $finalXmlDom = InvoiceExtension::fromString($instance->signedInvoice);
            $finalComputedHash = $finalXmlDom->computeXmlDigest();
            if ($finalComputedHash !== $instance->hash) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("ZATCA Hash Debug - Mismatch detected. Recomputing hash from final XML.");
                    error_log("ZATCA Hash Debug - Old: " . $instance->hash);
                    error_log("ZATCA Hash Debug - New: " . $finalComputedHash);
                }
                $instance->hash = $finalComputedHash;

                // Recompute digital signature over the binary hash (not base64)
                $newHashBinary = base64_decode($finalComputedHash);
                $newSignature = base64_encode($certificate->getPrivateKey()->sign($newHashBinary));
                $instance->digitalSignature = $newSignature;

                // Rebuild the UBL Extension with updated digest and signature
                $newUblExtension = (new InvoiceSignatureBuilder())
                    ->setCertificate($certificate)
                    ->setInvoiceDigest($finalComputedHash)
                    ->setSignatureValue($newSignature)
                    ->buildSignatureXml();

                // Format the new signature XML
                // $newFormattedSignature = self::formatSignatureXml($newUblExtension);

                $containerXml = '<ext:UBLExtensions>' . PHP_EOL . '        ' . $newUblExtension . PHP_EOL . '</ext:UBLExtensions>';
                // Replace existing UBLExtensions container
                $instance->signedInvoice = preg_replace(
                    '/<ext:UBLExtensions>.*?<\/ext:UBLExtensions>/s',
                    addcslashes($containerXml, '\\\\$'),
                    $instance->signedInvoice,
                    1
                );

                // Regenerate QR with the recomputed hash to keep QR cryptographic fields consistent
                $newQr = QRCodeGenerator::createFromTags(
                    $finalXmlDom->generateQrTagsArray($certificate, $instance->hash, $instance->digitalSignature)
                )->encodeBase64();

                // Replace embedded QR value inside the signed XML
                $oldQr = $instance->qrCode;
                $updatedXml = str_replace(
                    '<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . $oldQr . '</cbc:EmbeddedDocumentBinaryObject>',
                    '<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . $newQr . '</cbc:EmbeddedDocumentBinaryObject>',
                    $instance->signedInvoice
                );

                // Fallback: regex replace if exact match not found (spacing/newlines)
                if ($updatedXml === $instance->signedInvoice) {
                    $updatedXml = preg_replace(
                        '/(<cbc:EmbeddedDocumentBinaryObject\s+mimeCode=\"text\/plain\">)(.*?)(<\/cbc:EmbeddedDocumentBinaryObject>)/s',
                        '$1' . addcslashes($newQr, '\\\\$') . '$3',
                        $instance->signedInvoice,
                        1
                    );
                }

                $instance->signedInvoice = $updatedXml;
                $instance->qrCode = $newQr;
                
                // Re-apply canonical XML formatting after updates
                $instance->signedInvoice = self::formatEntireXml($instance->signedInvoice);
            }
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ZATCA Hash Debug - Failed to recompute final hash: ' . $e->getMessage());
            }
        }

        return $instance;
    }

    /**
     * Saves the signed invoice as an XML file.
     *
     * @param string $filename (Optional) File path to save the XML.
     * @param string|null $outputDir (Optional) Directory name. Set to null if $filename contains the full file path.
     * @return self
     * @throws ZatcaStorageException If the XML file cannot be saved.
     */
    public function saveXMLFile(string $filename = 'signed_invoice.xml', ?string $outputDir = 'output'): self
    {
        \Famcare\ZatcaInvoicing\Helpers\Storage::putTo($outputDir, $filename, $this->signedInvoice);
        return $this;
    }

    /**
     * Get the signed XML string.
     *
     * @return string
     */
    public function getXML(): string
    {
        return $this->signedInvoice;
    }

    /**
     * Returns the QR node string.
     *
     * @param string $QRCode
     * @return string
     */
    private function getQRNode(string $QRCode): string
    {
        return "<cac:AdditionalDocumentReference>
        <cbc:ID>QR</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode=\"text/plain\">$QRCode</cbc:EmbeddedDocumentBinaryObject>
        </cac:Attachment>
    </cac:AdditionalDocumentReference>
    <cac:Signature>
        <cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID>
        <cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod>
    </cac:Signature>";
    }
    /**
     * Get signed invoice XML.
     *
     * @return string
     */
    public function getInvoice(): string
    {
        return $this->signedInvoice;
    }

    /**
     * Get invoice hash.
     *
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Get QR Code.
     *
     * @return string
     */
    public function getQRCode(): string
    {
        return $this->qrCode;
    }

    /**
     * Get the certificate used for signing.
     *
     * @return Certificate
     */
    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }

    /**
     * Formats the signature XML to ensure proper indentation using C14N11 standards.
     *
     * @param string $xml The signature XML string.
     * @return string The formatted signature XML.
     */
    private static function formatSignatureXml(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        
        // Use canonical XML formatting for proper XML structure
        // C14N provides standardized XML formatting that's consistent with XML standards
        // Parameters: exclusive=false (use inclusive canonicalization), withComments=false, xpath=null, xpathNode=null
        $formattedXml = $dom->C14N(false, false, null, null);
        
        // Ensure proper indentation for insertion into main XML
        // Split into lines and add proper indentation
        $lines = explode("\n", $formattedXml);
        $formattedLines = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) {
                continue; // Skip empty lines
            }
            
            // Add 4 spaces of indentation to each non-empty line
            $formattedLines[] = '    ' . $line;
        }
        
        return implode("\n", $formattedLines);
    }

    /**
     * Applies canonical XML formatting to the entire XML string.
     *
     * @param string $xml The XML string to format.
     * @return string The formatted XML string.
     */
    private static function formatEntireXml(string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml);
        $dom->encoding = 'UTF-8';
        $formattedXml = $dom->saveXML();

        // Convert 2-space indentation to 4-space indentation.
        $formattedXml = preg_replace_callback('/^([ ]+)/m', function ($matches) {
            return str_repeat('    ', strlen($matches[1]) / 2);
        }, $formattedXml);

        // TASK 1: Remove the newline and indentation before <ext:UBLExtensions>.
        // This effectively moves the tag up to the end of the previous line.
        $formattedXml = preg_replace('/>\s*<ext:UBLExtensions>/', '><ext:UBLExtensions>', $formattedXml);

        // TASK 2: Reduce indentation for the content within the UBLExtensions block.
        // This finds the block and processes its inner content with a callback.
        $formattedXml = preg_replace_callback(
            '/(<ext:UBLExtensions>)(.*?)(<\/ext:UBLExtensions>)/s',
            function ($matches) {
                // $matches[2] contains the content between the start and end tags.
                // We remove exactly 4 spaces from the beginning of each line inside this block.
                $innerContent = preg_replace('/^ {4}/m', '', $matches[2]);
                
                // Reassemble the block with the de-indented content.
                return $matches[1] . $innerContent . $matches[3];
            },
            $formattedXml
        );

        return $formattedXml;
    }
}