<?php

namespace Bundles\Gateways\ProductList\Lib;

use ListsConfig, DB, Path;

class GoogleContextFeed extends FeedCommon
{

    protected $flushCounter = 500;

    protected $category = null;

    protected $csvColumnHeaders = [
        'Property ID',
        'Property name',
        'Final URL',
        'Image URL',
        'Destination name',
        'Price',
        'Star rating',
    ];

    /**
     * @return string
     */
    public function get(): string
    {
        DB::transaction(function () {
            while ($this->handleChunk()) ;
        });
        return $this->convertToRequiredFormat();
    }

    /**
     * @return bool
     */
    protected function handleChunk(): bool
    {
        $chunk = [];
        $productIds = [];
        $this->productsIterator(
            $this->chunkPos,
            $this->chunkSize,
            function ($row) use (&$chunk, &$productIds) {
                $productIds [] = $row->id;
                $add = [
                    'pages_id' => $row->id,
                    'name' => $row->name,
                    'url' => $this->getUrl($row->id),
                    'resName' => $row->resortName,
                    'avg_cost' => $row->avg_cost,
                    'code' => $row->ksb_code,
                ];
                $chunk [] = $add;
            },
            [
                'productAttrs' => ['id', 'name', 'avg_cost', 'ksb_code'],
                'productTypes' => [OBJ_PAGE_TYPE],
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToCrossListsMapping = $this->getProdIdsToCrossListsMapping($productIds);
        $prodIdsToToursMapping = $this->getProdIdsToToursMapping($productIds);
        foreach ($chunk as &$it) {
            $tour = $prodIdsToToursMapping[$it['pages_id']] ?? null;
            if ($tour) {
                $pic = 'https:'
                    . ($prodIdsToPicsMapping[$it['pages_id']][0]['thumbs'][config('gallery.size.gateways_product')]
                        ?? $prodIdsToPicsMapping['empty'][0]['thumbs'][config('gallery.size.gateways_product')]);
                $it['pic'] = $pic;
                $it['stars'] = $this->parseStars($prodIdsToCrossListsMapping[$it['pages_id']]);

                $this->result->add($it);
            }
        }

        $this->chunkPos += $this->chunkSize;

        return true;
    }

    /**
     * @param array|null $crossListsMapping
     * @return int|null
     */
    protected function parseStars(?array &$crossListsMapping): ?int
    {
        $listMap = ListsConfig::getListMap();
        if (isset($crossListsMapping[$listMap['1star']])) {
            return 1;
        }
        if (isset($crossListsMapping[$listMap['2star']])) {
            return 2;
        }
        if (isset($crossListsMapping[$listMap['3star']])) {
            return 3;
        }
        if (isset($crossListsMapping[$listMap['4star']])) {
            return 4;
        }
        if (isset($crossListsMapping[$listMap['5star']])) {
            return 5;
        }
        return null;
    }

    /**
     * @return string
     */
    protected function convertToRequiredFormat(): string
    {
        $csv = $this->saveToCsv("google-context-");
        $zippedFile = Path::join(storage_path('etc'), "google-context.zip");
        $this->toZip($csv, $zippedFile);
        return $zippedFile;
    }

    /**
     * @param string $fileNamePrefix
     * @return string
     */
    protected function saveToCsv(string $fileNamePrefix): string
    {
        $uid = uniqid($fileNamePrefix);
        $tmpFile = '/tmp/' . $uid . '.csv';
        $file = fopen($tmpFile, 'w');
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($file, $this->csvColumnHeaders);
        while ($row = $this->result->getNext()) {
            $csvRow = [
                $row['pages_id'],
                $row['name'],
                $row['url'],
                $row['pic'],
                $row['resName'],
                isset($row['avg_cost']) ? round($row['avg_cost'] * 0.8, 0) . ' RUB' : '',
                $row['stars'],
            ];
            fputcsv($file, $csvRow);
        }
        fclose($file);
        return $tmpFile;
    }

}
