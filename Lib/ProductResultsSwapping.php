<?php

namespace Bundles\Gateways\ProductList\Lib;

use Closure;

class ProductResultsSwapping extends ProductResultsContract
{

    protected $options = [
        'chunkSize' => 500,
        'chunkFilesPrefix' => 'Bundles.Gateways.ProductList.Lib.ProductResultsSwapping'
    ];

    protected $chunks = [];

    protected $the1stItemsOfEachChunk = [];

    protected $count = 0;

    /**
     * ProductResultsSwapping constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * @param array $it
     */
    public function add(array &$it): void
    {
        $this->results [] = $it;
        ++$this->count;
        if (count($this->results) === $this->options['chunkSize']) {
            $this->flushResults();
        }
    }

    /**
     * @return array|null
     */
    public function getNext(): ?array
    {
        $out = array_shift($this->results);
        if (is_null($out) && count($this->chunks) > 0) {
            $chunkFileName = array_shift($this->chunks);
            $this->results = unserialize(file_get_contents($chunkFileName));
            unlink($chunkFileName);
            $out = array_shift($this->results);
        }
        $this->count === 0 ?: --$this->count;
        return $out;
    }

    /**
     * @param Closure $cmp
     */
    public function usort(Closure $cmp): void
    {
        $this->flushResults();
        foreach ($this->chunks as $i => $chunk) {
            $this->results = unserialize(file_get_contents($chunk));
            unlink($chunk);
            usort($this->results, $cmp);
            $this->chunks[$i] = tempnam(sys_get_temp_dir(), $this->options['chunkFilesPrefix']);
            $this->flushResults($this->chunks[$i]);
        }
        $this->mergeSort($cmp);
    }

    /**
     * @return int
     * @see \Bundles\Gateways\ProductList\Lib\ProductResultsContract::count()
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * @param string|null $chunkFileName
     */
    protected function flushResults(string $chunkFileName = null): void
    {
        $theName = $chunkFileName ?? tempnam(sys_get_temp_dir(), $this->options['chunkFilesPrefix']);
        file_put_contents($theName, serialize($this->results));
        $chunkFileName ?: $this->chunks [] = $theName;
        $this->results = [];
    }

    /**
     * @param Closure $cmp
     */
    protected function mergeSort(Closure $cmp): void
    {
        $sources = [];
        foreach ($this->chunks as $i => $chunk) {
            if ($i !== 0) {
                $sources [] = new ProductResultsSwapping();
                $sources[count($sources) - 1]->chunks [] = $chunk;
            }
        }
        $this->chunks = array_slice($this->chunks, 0, 1);
        while ($source = array_shift($sources)) {
            $this->merge($source, $cmp);
        }
    }

    /**
     * @param ProductResultsSwapping $source
     * @param Closure $cmp
     */
    protected function merge(ProductResultsSwapping $source, Closure $cmp): void
    {
        $firstSource = new ProductResultsSwapping();
        $firstSource->chunks = $this->chunks;
        $this->chunks = [];
        $firstSource->results = $this->results;
        $this->results = [];
        $this->count = 0;
        $secondSource = $source;
        $firstSourceIt = null;
        $secondSourceIt = null;
        do {
            if (is_null($firstSourceIt)) {
                $firstSourceIt = $firstSource->getNext();
            }
            if (is_null($secondSourceIt)) {
                $secondSourceIt = $secondSource->getNext();
            }
            if (is_null($firstSourceIt) || is_null($secondSourceIt)) {
                break;
            }
            if ($cmp($firstSourceIt, $secondSourceIt) <= 0) {
                $this->add($firstSourceIt);
                $firstSourceIt = null;
            } else {
                $this->add($secondSourceIt);
                $secondSourceIt = null;
            }
        } while (true);
        $remainingResults = is_null($firstSourceIt) ? $secondSource : $firstSource;
        if (!is_null($firstSourceIt)) {
            $this->add($firstSourceIt);
        }
        if (!is_null($secondSourceIt)) {
            $this->add($secondSourceIt);
        }
        while ($it = $remainingResults->getNext()) {
            $this->add($it);
        }
        $this->flushResults();
    }

}
