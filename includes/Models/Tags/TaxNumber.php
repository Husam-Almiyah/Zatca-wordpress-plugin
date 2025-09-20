<?php

namespace Famcare\ZatcaInvoicing\Models\Tags;

use Famcare\ZatcaInvoicing\Models\Tag;

class TaxNumber extends Tag
{
    public function __construct($value)
    {
        parent::__construct(2, $value);
    }
}
