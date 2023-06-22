<?php

namespace Bundles\Gateways\ProductList\Lib;

use Closure;

class ProductResultsContract
{

    protected $results = [];

    /**
     * @param array $it
     */
    public function add(array &$it): void
    {
    }

    /**
     * @return array|null
     */
    public function getNext(): ?array
    {
    }

    /**
     * @param \Closure $cmp
     */
    public function usort(Closure $cmp): void
    {
    }

    /**
     * Returns amount of items in results
     * @return int
     */
    public function count(): int
    {
    }

}
