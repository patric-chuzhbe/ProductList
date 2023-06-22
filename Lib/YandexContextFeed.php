<?php

namespace Bundles\Gateways\ProductList\Lib;

use ListsConfig, DB, Path;

class YandexContextFeed extends FeedCommon
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
        'Score',
        'Max score',
        'Facilities',
        'Description',
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
                    'resort' => $row->resortName,
                    'rating' => $row->rating,
                    'avg_cost' => $row->avg_cost,
                    'code' => $row->ksb_code,
                ];
                $chunk [] = $add;
            },
            [
                'productAttrs' => ['id', 'name', 'rating', 'avg_cost', 'ksb_code'],
                'productTypes' => [OBJ_PAGE_TYPE],
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToCrossListsMapping = $this->getProdIdsToCrossListsMapping($productIds);
        $prodIdsToFeedSystemsMapping = $this->getProdIdsToFeedsMappingBySystemId($productIds);
        $prodIdsToFeedTypesMapping = $this->getProdIdsToFeedsMappingByTypeId($productIds);
        $prodIdsToToursMapping = $this->getProdIdsToToursMapping($productIds);
        foreach ($chunk as &$it) {
            $tour = $prodIdsToToursMapping[$it['pages_id']] ?? null;
            if ($tour) {
                $pic = 'https:'
                    . ($prodIdsToPicsMapping[$it['pages_id']][0]['thumbs'][config('gallery.size.gateways_product')]
                        ?? $prodIdsToPicsMapping['empty'][0]['thumbs'][config('gallery.size.gateways_product')]);
                $it['pic'] = $pic;
                $it['stars'] = $this->parseStars($prodIdsToCrossListsMapping[$it['pages_id']]);
                $it['facilities'] = $this->parseFacilities(
                    $prodIdsToCrossListsMapping[$it['pages_id']],
                    $prodIdsToFeedSystemsMapping[$it['pages_id']],
                    $prodIdsToFeedTypesMapping[$it['pages_id']]
                );
                $it['tours_id'] = $tour->id;

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
     * @param array|null $crossListsMapping
     * @param array|null $feedSystemsMapping
     * @param array|null $feedTypesMapping
     * @return array
     */
    protected function parseFacilities(?array &$crossListsMapping, ?array &$feedSystemsMapping, ?array &$feedTypesMapping): array
    {
        $out = [];
        $listMap = ListsConfig::getListMap();
        if (isset($crossListsMapping[$listMap['children_animation']])) {
            $out [] = 'Детская анимация';
        }
        if (isset($crossListsMapping[$listMap['karaoke']])) {
            $out [] = 'Караоке';
        }
        if (isset($crossListsMapping[$listMap['night_club']])) {
            $out [] = 'Ночной клуб';
        }
        if (isset($crossListsMapping[$listMap['bowling']])) {
            $out [] = 'Боулинг';
        }
        if (isset($crossListsMapping[$listMap['aquapark']])) {
            $out [] = 'Аквапарк';
        }
        if (isset($crossListsMapping[$listMap['outdoor_pool']])) {
            if (isset($crossListsMapping[$listMap['heated_pool']])) {
                $out [] = 'Подогреваемый бассейн';
            } else {
                $out [] = 'Открытый бассейн';
            }
        }
        if (isset($crossListsMapping[$listMap['indoor_pool']])) {
            $out [] = 'Крытый бассейн';
        }
        if (isset($crossListsMapping[$listMap['private_beach']])) {
            $out [] = 'Собственный пляж';
        } elseif (
            isset($crossListsMapping[$listMap['unequipped_beach']])
            || isset($crossListsMapping[$listMap['equipped_beach']])
            || isset($crossListsMapping[$listMap['beach_distance']])) {
            $out [] = 'Пляж';
        }
        if (isset($crossListsMapping[$listMap['common_wifi']])) {
            $out [] = 'WI-FI';
        }
        if (isset($crossListsMapping[$listMap['spa']])) {
            $out [] = 'СПА-центр';
        }
        if (isset($crossListsMapping[$listMap['sea_distance']])) {
            $out [] = 'Расстояние до моря: ' . $crossListsMapping[$listMap['sea_distance']];
        }
        if (isset($crossListsMapping[$listMap['pump_room_distance']])) {
            $out [] = 'Расстояние до бювета: ' . $crossListsMapping[$listMap['pump_room_distance']];
        }
        if (isset($crossListsMapping[$listMap['downtown_distance']])) {
            $out [] = 'Расстояние до центра: ' . $crossListsMapping[$listMap['downtown_distance']];
        }
        if (isset($crossListsMapping[$listMap['elevator_distance']])) {
            $out [] = 'Расстояние до подъемников: ' . $crossListsMapping[$listMap['elevator_distance']];
        }

        # feeds:
        if ($feedSystemsMapping) {
            foreach ($this->feedSystems as $id) {
                if (isset($feedSystemsMapping[$id])) {
                    $out [] = 'Всё включено';
                    break;
                }
            }
        }
        if ($feedTypesMapping) {
            foreach ($this->feedTypeIds as $id) {
                if (isset($feedTypesMapping[$id])) {
                    $out [] = 'Шведский стол';
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return string
     */
    protected function convertToRequiredFormat(): string
    {
        $csv = $this->saveToCsv("yandex-context-");
        $zippedFile = Path::join(storage_path('etc'), "yandex-context.zip");
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
            $description = isset($row['avg_cost']) ? 'Цена от ' . round($row['avg_cost'] * 0.8, 0) . ' RUB. ' : '';
            $description .= "Реальные отзывы туристов. Описание и фото. Бронируйте онлайн!";
            $csvRow = [
                $row['pages_id'],
                $row['name'],
                $row['url'],
                $row['pic'],
                $row['resort'],
                isset($row['avg_cost']) ? round($row['avg_cost'] * 0.8, 0) . ' RUB' : '',
                $row['stars'],
                $row['rating'],
                isset($row['rating']) ? 10 : '',
                implode(';', $row['facilities']),
                $description
            ];
            fputcsv($file, $csvRow);
        }
        fclose($file);
        return $tmpFile;
    }

}
