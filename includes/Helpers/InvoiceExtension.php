<?php
namespace Famcare\ZatcaInvoicing\Helpers;

use DOMDocument;
use DOMElement;
use DOMException;
use DOMXPath;
use WeakMap;
use InvalidArgumentException;
use Famcare\ZatcaInvoicing\Models\Tags\{Seller, TaxNumber, PublicKey, InvoiceTotalAmount, InvoiceTaxAmount, InvoiceHash, InvoiceDigitalSignature, InvoiceDate, CertificateSignature};

/**
 * Class InvoiceExtension
 *
 * This class wraps a DOMElement and provides utility methods for managing
 * invoice XML extensions, including parsing, modifying, and exporting XML.
 *
 * @package Famcare\ZatcaInvoicing\Helpers
 */
class InvoiceExtension
{
    public const NS_PREFIX = '__uxml_ns_';

    /**
     * WeakMap to store DOMElement => InvoiceExtension mapping.
     *
     * @var WeakMap<DOMElement, self>|null|false
     */
    private static $elements = null;

    /** @var DOMElement */
    protected DOMElement $element;

    /**
     * Create an InvoiceExtension instance from an XML string.
     *
     * @param string $xmlString The XML string.
     * @return self The InvoiceExtension instance.
     * @throws InvalidArgumentException if the XML cannot be parsed.
     */
    public static function fromString(string $xmlString): self
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = true;

        // Ensure proper indentation if not already present.
        if (!str_contains($xmlString, '    <cbc:ProfileID>')) {
            $xmlString = preg_replace('/^[ ]+(?=<)/m', '$0$0', $xmlString);
        }

        if ($doc->loadXML($xmlString) === false) {
            throw new InvalidArgumentException('Failed to parse XML string');
        }
        return new self($doc->documentElement);
    }

    /**
     * Create an InvoiceExtension instance from a DOMElement.
     *
     * @param DOMElement $element The DOM element.
     * @return self The wrapped InvoiceExtension instance.
     */
    public static function fromElement(DOMElement $element): self
    {
        if (self::$elements) {
            return self::$elements->offsetExists($element)
                ? self::$elements->offsetGet($element)
                : new self($element);
        }
        // Fallback map not available; always return new wrapper (avoid dynamic props)
        return new self($element);
    }

    /**
     * Create a new InvoiceExtension instance with a new element.
     *
     * @param string $name The tag name.
     * @param string|null $value The element value or null.
     * @param array<string, string> $attrs The element attributes.
     * @param DOMDocument|null $doc The owner document; if null, a new one is created.
     * @return self The new InvoiceExtension instance.
     * @throws DOMException if element creation fails.
     */
    public static function newInstance(string $name, ?string $value = null, array $attrs = [], ?DOMDocument $doc = null): self
    {
        $targetDoc = $doc ?? new DOMDocument();

        // Determine the namespace from the tag prefix, if available.
        $prefix = strstr($name, ':', true) ?: '';
        $namespace = $attrs[empty($prefix) ? 'xmlns' : "xmlns:$prefix"] ?? $targetDoc->lookupNamespaceUri($prefix);

        try {
            $domElement = $namespace === null
                ? $targetDoc->createElement($name)
                : $targetDoc->createElementNS($namespace, $name);
            if ($domElement === false) {
                throw new DOMException('Failed to create DOMElement');
            }

            if ($doc === null) {
                $targetDoc->appendChild($domElement);
            }

            if ($value !== null) {
                $domElement->textContent = $value;
            }

            foreach ($attrs as $attrName => $attrValue) {
                if ($attrName === 'xmlns' || strpos($attrName, 'xmlns:') === 0) {
                    $domElement->setAttributeNS('http://www.w3.org/2000/xmlns/', $attrName, $attrValue);
                } else {
                    $domElement->setAttribute($attrName, $attrValue);
                }
            }
        } catch (\Exception $ex) {
            throw new \Exception('Error: ' . $ex->getMessage() . ', name: ' . $name);
        }
        return new self($domElement);
    }

    /**
     * Private constructor.
     *
     * @param DOMElement $element The DOM element instance.
     */
    private function __construct(DOMElement $element)
    {
        if (self::$elements === null) {
            self::$elements = class_exists(WeakMap::class) ? new WeakMap() : false;
        }
        $this->element = $element;
        if (self::$elements) {
            self::$elements->offsetSet($this->element, $this);
        }
    }

    /**
     * Get the underlying DOMElement.
     *
     * @return DOMElement The DOM element.
     */
    public function getElement(): DOMElement
    {
        return $this->element;
    }

    /**
     * Get the parent element wrapped as an InvoiceExtension.
     *
     * @return self The parent instance, or self if no parent exists.
     */
    public function getParent(): self
    {
        $parentNode = $this->element->parentNode;
        return ($parentNode !== null && $parentNode instanceof DOMElement)
            ? self::fromElement($parentNode)
            : $this;
    }

    /**
     * Check if the element has no child nodes.
     *
     * @return bool True if empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return $this->element->childNodes->length === 0;
    }

    /**
     * Add a child element.
     *
     * @param string $name The tag name of the child.
     * @param string|null $value The value of the child, or null.
     * @param array<string, string> $attrs The attributes for the child.
     * @return self The new child InvoiceExtension.
     * @throws DOMException if child creation fails.
     */
    public function addChild(string $name, ?string $value = null, array $attrs = []): self
    {
        $child = self::newInstance($name, $value, $attrs, $this->element->ownerDocument);
        $this->element->appendChild($child->element);
        return $child;
    }

    /**
     * Find all elements matching the given XPath query.
     *
     * @param string $xpath The XPath query relative to this element.
     * @param int|null $limit Optional maximum number of results.
     * @return self[] Array of matched InvoiceExtension instances.
     */
    public function findAll(string $xpath, ?int $limit = null): array
    {
        $namespaces = [];
        $xpath = preg_replace_callback('/{(.+?)}/', static function ($match) use (&$namespaces) {
            $ns = $match[1];
            if (!isset($namespaces[$ns])) {
                $namespaces[$ns] = self::NS_PREFIX . count($namespaces);
            }
            return $namespaces[$ns] . ':';
        }, $xpath);

        $xpathInstance = new DOMXPath($this->element->ownerDocument);
        foreach ($namespaces as $ns => $prefix) {
            $xpathInstance->registerNamespace($prefix, $ns);
        }

        $results = [];
        $domNodes = $xpathInstance->query($xpath, $this->element);
        foreach ($domNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $results[] = self::fromElement($node);
            if ($limit !== null && --$limit <= 0) {
                break;
            }
        }
        return $results;
    }

    /**
     * Find the first element matching the XPath query.
     *
     * @param string $xpath The XPath query.
     * @return self|null The first matched instance or null if not found.
     */
    public function find(string $xpath): ?self
    {
        $results = $this->findAll($xpath, 1);
        return $results[0] ?? null;
    }

    /**
     * Remove this element from its parent.
     *
     * After calling this method, the instance becomes unusable.
     */
    public function remove(): void
    {
        $parent = $this->element->parentNode;
        if ($parent !== null) {
            $parent->removeChild($this->element);
        }
    }

    /**
     * Remove the first element matching the XPath query.
     *
     * @param string $xpath The XPath query.
     * @return self The current instance.
     */
    public function removeByXpath(string $xpath): self
    {
        if ($node = $this->find($xpath)) {
            $node->remove();
        }
        return $this;
    }

    /**
     * Remove the parent of the first element matching the XPath query.
     *
     * @param string $xpath The XPath query.
     * @return self The current instance.
     */
    public function removeParentByXpath(string $xpath): self
    {
        if ($node = $this->find($xpath)) {
            $node->getParent()->remove();
        }
        return $this;
    }

    /**
     * Get the trimmed text content of the element.
     *
     * @return string The text content.
     */
    public function toText(): string
    {
        return trim($this->element->textContent);
    }

    /**
     * Export the element and its children as an XML string.
     *
     * @param string|null $version The XML version; null for no declaration.
     * @param string $encoding The document encoding.
     * @param bool $format Whether to format the output.
     * @return string The XML string.
     */
    public function toXml(?string $version = '1.0', string $encoding = 'UTF-8', bool $format = true): string
    {
        $doc = new DOMDocument();
        if ($version === null) {
            $doc->xmlStandalone = true;
        } else {
            $doc->xmlVersion = $version;
        }
        $doc->encoding = $encoding;
        $doc->formatOutput = $format;

        $rootNode = $doc->importNode($this->element, true);
        if ($rootNode !== false) {
            $doc->appendChild($rootNode);
        }
        $xmlString = $version === null
            ? $doc->saveXML($doc->documentElement)
            : $doc->saveXML();
        unset($doc);
        return $xmlString;
    }

    /**
     * Generate an array of Tag objects for QR code generation based on the current invoice XML.
     *
     * @param Certificate $certificate The certificate instance.
     * @param string|null $invoiceDigest The Base64-encoded invoice digest.
     * @param string|null $signatureValue The digital signature.
     * @return array An array of Tag objects.
     */
    public function generateQrTagsArray(Certificate $certificate, ?string $invoiceDigest, ?string $signatureValue): array
    {
        if (!$invoiceDigest) {
            $invoiceDigest = $this->computeXmlDigest();
        }

        if (!$signatureValue) {
            $signatureValue = base64_encode($certificate->getPrivateKey()->sign(base64_decode($invoiceDigest)));
        }

        $issueDateNode = $this->find("cbc:IssueDate");
        $issueDate = $issueDateNode ? $issueDateNode->toText() : '';

        $issueTimeNode = $this->find("cbc:IssueTime");
        $issueTime = $issueTimeNode ? $issueTimeNode->toText() : '';
        // Use the exact IssueDate and IssueTime from the XML to avoid KSA-25 mismatch
        $invoiceDateTime = ($issueDate !== '' && $issueTime !== '') ? ($issueDate . 'T' . $issueTime) : '';

        // Prefer RegistrationName, fallback to PartyName if needed (matches common ZATCA examples)
        $sellerNameNode = $this->find("cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName");
        $sellerName = $sellerNameNode ? $sellerNameNode->toText() : '';
        if ($sellerName === '') {
            $sellerNameFallback = $this->find("cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name");
            $sellerName = $sellerNameFallback ? $sellerNameFallback->toText() : '';
        }
        // Ensure no thousands separators in amounts used for TLV/QR

        $sellerTaxNumberNode = $this->find("cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID");
        $sellerTaxNumber = $sellerTaxNumberNode ? $sellerTaxNumberNode->toText() : '';

        $invoiceTotalAmountNode = $this->find("cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount");
        $invoiceTotalAmount = $invoiceTotalAmountNode ? $invoiceTotalAmountNode->toText() : '';

        // Use the first TaxTotal element's text content (matches ZATCA-master exactly)
        $invoiceTaxAmountNode = $this->find("cac:TaxTotal/cbc:TaxAmount");
        $invoiceTaxAmount = $invoiceTaxAmountNode ? $invoiceTaxAmountNode->toText() : '';

        // Get public key in proper format (exactly like ZATCA-master)
        $publicKeyData = $certificate->getRawPublicKey();
        
        $qrTags = [
            new Seller($sellerName),
            new TaxNumber($sellerTaxNumber),
            new InvoiceDate($invoiceDateTime),
            new InvoiceTotalAmount($invoiceTotalAmount),
            new InvoiceTaxAmount($invoiceTaxAmount),
            new InvoiceHash($invoiceDigest),
            new InvoiceDigitalSignature($signatureValue), // Keep as base64 string per reference
            new PublicKey(base64_decode($publicKeyData)) // Decode to binary per reference (matches ZATCA-master)
        ];

        // For Simplified Tax Invoices (Phase 2), add the certificate signature
        $invoiceTypeCodeNode = $this->find("cbc:InvoiceTypeCode");
        $isSimplified = $invoiceTypeCodeNode && str_starts_with($invoiceTypeCodeNode->getElement()->getAttribute('name'), "02");

        if ($isSimplified) {
            $certSignature = $certificate->getCertSignature();
            $qrTags[] = new CertificateSignature($certSignature); // Matches ZATCA-master getCertificateSignature method
        }

        return $qrTags;
    }

    /**
     * Compute the Base64-encoded XML digest.
     *
     * @return string The Base64-encoded digest.
     */
    public function computeXmlDigest(): string
    {
        $clonedXml = clone $this;

        // Remove unwanted elements for digest computation.
        $clonedXml->removeByXpath('ext:UBLExtensions');
        $clonedXml->removeByXpath('cac:Signature');
        $clonedXml->removeParentByXpath('cac:AdditionalDocumentReference/cbc:ID[. = "QR"]');

        return base64_encode(hash('sha256', $clonedXml->getElement()->C14N(false, false), true));
    }

    /**
     * Return the XML string when the object is cast to a string.
     *
     * @return string The XML string.
     */
    public function __toString(): string
    {
        return $this->toXml(null, 'UTF-8', false);
    }
}
