<?php

namespace Famcare\ZatcaInvoicing\Models\Tags;

use Famcare\ZatcaInvoicing\Models\Tag;

class InvoiceDigitalSignature extends Tag
{
    public function __construct($value)
    {
        parent::__construct(7, $value);
    }
}
