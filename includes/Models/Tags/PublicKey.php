<?php

namespace Famcare\ZatcaInvoicing\Models\Tags;

use Famcare\ZatcaInvoicing\Models\Tag;

class PublicKey extends Tag
{
    public function __construct($value)
    {
        parent::__construct(8, $value);
    }
}
