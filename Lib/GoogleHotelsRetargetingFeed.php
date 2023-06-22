<?php

namespace Bundles\Gateways\ProductList\Lib;

use DB, Path, ListsConfig;

class GoogleHotelsRetargetingFeed extends FeedCommon
{

    const MAX_RESORT_NAME = 25;

    protected $csvColumnHeaders = [
        'Property ID',
        'Property name',
        'Final URL',
        'Destination name',
        'Image URL',
        'Price',
        'Star rating',
        'Description',
    ];

    /**
     * FacebookFeed constructor.
     * @param ProductResultsContract $resultsVault
     */
    public function __construct(ProductResultsContract $resultsVault)
    {
        parent::__construct($resultsVault);
        $this->crossListsId = [
            ListsConfig::getListMap('1star'),
            ListsConfig::getListMap('2star'),
            ListsConfig::getListMap('3star'),
            ListsConfig::getListMap('4star'),
            ListsConfig::getListMap('5star'),
        ];
    }

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
                    'url' => env('APP_URL') . ltrim($row->url, '/'),
                    'destinationName' => $this->cutResortName($row->resortName),
                    'avg_cost' => (int)trim($row->avg_cost) ?: null,
                ];
                $chunk [] = $add;
            },
            [
                'productAttrs' => ['id', 'name', 'id', 'avg_cost', 'url'],
                'productTypes' => [OBJ_PAGE_TYPE]
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToCrossListsMapping = $this->getProdIdsToCrossListsMapping($productIds);
        foreach ($chunk as &$it) {
            $it['stars'] = $this->parseStars($prodIdsToCrossListsMapping[$it['pages_id']]);

            # Handle 'description' and 'avg_cost':
            $it['description'] = '';
            if ($it['avg_cost']) {
                $it['avg_cost'] = "{$it['avg_cost']} RUB";
                $it['description'] = "Цены от {$it['avg_cost']}. ";
            }
            $it['description'] .= "Реальные отзывы туристов. Описание и фото. Бронируйте онлайн!";

            # Handle 'pic':
            $pic = 'https:'
                . ($prodIdsToPicsMapping[$it['pages_id']][0]['thumbs'][config('gallery.size.gateways_product')]
                    ?? $prodIdsToPicsMapping['empty'][0]['thumbs'][config('gallery.size.gateways_product')]);
            $it['pic'] = $pic;

            $this->result->add($it);
        }

        $this->chunkPos += $this->chunkSize;

        return true;
    }

    /**
     * @return string
     */
    protected function convertToRequiredFormat(): string
    {
        $csv = $this->saveToCsv("google-hotels-retargeting-");
        $zippedFile = Path::join(storage_path('etc'), "google-hotels-retargeting.zip");
        $this->toZip($csv, $zippedFile);
        return $zippedFile;
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
     * @param string $name
     * @return string
     */
    protected function cutResortName(string $name): string
    {
        $out = mb_substr($name, 0, self::MAX_RESORT_NAME);
        return mb_strlen($out) < mb_strlen($name)
            ? mb_substr($out, 0, mb_strlen($out) - 1) . '…'
            : $out;
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
                $row['destinationName'],
                $row['pic'],
                $row['avg_cost'],
                $row['stars'],
                $row['description'],
            ];
            fputcsv($file, $csvRow);
        }
        fclose($file);
        return $tmpFile;
    }

}
