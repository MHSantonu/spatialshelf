<?php

namespace App\Services\Catalog;

use App\Data\NearestMatch;

interface VectorIndex
{
    /** @param  list<float>  $vector */
    public function match(array $vector): NearestMatch;
}
