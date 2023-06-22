<?php

namespace Bundles\Gateways\ProductList\Lib;

use DB, Path, DescriptionsConfig;

class FacebookFeed extends FeedCommon
{

    protected $csvColumnHeaders = [
        'id',
        'availability',
        'condition',
        'description',
        'image_link',
        'link',
        'title',
        'price',
        'brand'
    ];

    /**
     * FacebookFeed constructor.
     * @param ProductResultsContract $resultsVault
     */
    public function __construct(ProductResultsContract $resultsVault)
    {
        parent::__construct($resultsVault);
        $this->descriptionsIds = [
            DescriptionsConfig::getDescMap('complex'),
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
                    'avg_cost' => trim($row->avg_cost),
                    'code' => $row->ksb_code,
                    'url' => $this->getUrl($row->id),
                    'name' => $row->name,
                ];
                $chunk [] = $add;
            },
            [
                'productAttrs' => ['id', 'avg_cost', 'ksb_code', 'name'],
                'productTypes' => [OBJ_PAGE_TYPE, CAMP_PAGE_TYPE]
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToToursMapping = $this->getProdIdsToToursMapping($productIds);
        $prodIdsToDescrsMapping = $this->getProdIdsToDescsMapping($productIds);
        $descMap = DescriptionsConfig::getDescMap();
        foreach ($chunk as &$it) {
            $pic = 'https:'
                . ($prodIdsToPicsMapping[$it['pages_id']][0]['thumbs'][config('gallery.size.gateways_product')]
                    ?? $prodIdsToPicsMapping['empty'][0]['thumbs'][config('gallery.size.gateways_product')]);
            $it['tours_id'] = $prodIdsToToursMapping[$it['pages_id']]->id ?? null;
            $it['description'] = strip_tags($prodIdsToDescrsMapping[$it['pages_id']][$descMap['complex']] ?? null);
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
        return $this->saveToCsv();
    }

    /**
     * @return string
     */
    protected function saveToCsv(): string
    {
        $fileName = Path::join(storage_path('etc'), 'facebook.csv');
        $file = fopen($fileName, 'w');
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($file, $this->csvColumnHeaders);
        while ($obj = $this->result->getNext()) {
            $csvRow = [
                $obj['pages_id'],
                'in stock',
                'new',
                substr($obj['description'], 0, 5000),
                $obj['pic'],
                $obj['url'],
                $obj['name'],
                round($obj['avg_cost'], 0) . ' RUB',
                'Алеан'
            ];
            fputcsv($file, $csvRow);
        }
        fclose($file);
        return $fileName;
    }

}
