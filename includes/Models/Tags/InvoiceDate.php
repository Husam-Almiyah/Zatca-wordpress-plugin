<?php

namespace Famcare\ZatcaInvoicing\Models\Tags;

use Famcare\ZatcaInvoicing\Models\Tag;

class InvoiceDate extends Tag
{
    public function __construct($value)
    {
        parent::__construct(3, $value);
    }
}
