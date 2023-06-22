<?php

namespace Bundles\Gateways\ProductList\Lib;

use Closure;

class ProductResults extends ProductResultsContract
{

    /**
     * @param array $it
     */
    public function add(array &$it): void
    {
        $this->results [] = $it;
    }

    /**
     * Returns (pops) the next item from results
     * @return mixed
     */
    public function getNext(): ?array
    {
        return array_shift($this->results);
    }

    /**
     * @param \Closure $cmp
     */
    public function usort(Closure $cmp): void
    {
        usort($this->results, $cmp);
    }

    /**
     * Returns amount of items in results
     * @return int
     */
    public function count(): int
    {
        return count($this->results);
    }

}
