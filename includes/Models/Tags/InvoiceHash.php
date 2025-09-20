<?php

namespace Famcare\ZatcaInvoicing\Models\Tags;

use Famcare\ZatcaInvoicing\Models\Tag;

class InvoiceHash extends Tag
{
    public function __construct($value)
    {
        parent::__construct(6, $value);
    }
}
