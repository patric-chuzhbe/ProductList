<?php

namespace Bundles\Gateways\ProductList\Lib;

use ListsConfig, DB, Path;

class TheWorkPeriodFeed extends FeedCommon
{

    protected $flushCounter = 500;

    protected $category = null;

    protected $csvColumnHeaders = [
        'ID Отеля',
        'Название отеля',
        'URL',
        'Период работы',
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
                    'id' => $row->id,
                    'name' => $row->name,
                    'url' => $row->url,
                ];
                $chunk [] = $add;
            },
            [
                'productAttrs' => ['id', 'name', 'url'],
                'productTypes' => [OBJ_PAGE_TYPE],
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToWorkPeriods = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('work_period'),
            ['listAttributes' => ['name']]
        );
        foreach ($chunk as &$it) {
            $workPeriod = $prodIdsToWorkPeriods[$it['id']] ?? null;
            if ($workPeriod) {
                $workPeriod = array_values($workPeriod)[0];
                $listName = $workPeriod->list->name;
                $crossListVal = trim($workPeriod->val);
                $it['WorkPeriod'] = $listName;
                if ($crossListVal) {
                    $it['WorkPeriod'] .= " ($crossListVal)";
                }
            } else {
                $it['WorkPeriod'] = 'null';
            }
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
        $csv = $this->saveToCsv("the-work-period-feed-");
        $zippedFile = Path::join(storage_path('etc'), "the-work-period-feed.zip");
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
                $row['id'],
                $row['name'],
                $this->addDomainToUrl($row['url']),
                $row['WorkPeriod']
            ];
            fputcsv($file, $csvRow);
        }
        fclose($file);
        return $tmpFile;
    }

    /**
     * @param string $url
     * @return string
     */
    protected function addDomainToUrl(string $url): string
    {
        return rtrim(env('APP_URL'), '/') . '/' . ltrim($url, '/');
    }

}
