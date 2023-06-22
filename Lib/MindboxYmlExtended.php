<?php

namespace Bundles\Gateways\ProductList\Lib;

use DescriptionsConfig, Path, File, XMLWriter, Enjoin, ListsConfig, DB;

class MindboxYmlExtended extends FeedCommon
{

    protected $flushCounter = 100;

    /**
     * MindboxYmlExtended constructor.
     * @param ProductResultsContract $resultsVault
     */
    public function __construct(ProductResultsContract $resultsVault)
    {
        parent::__construct($resultsVault);
        $this->descriptionsIds = [
            DescriptionsConfig::getDescMap('complex'),
            DescriptionsConfig::getDescMap('exc_program'),
            DescriptionsConfig::getDescMap('act_program'),
            DescriptionsConfig::getDescMap('cru_route_desc'),
        ];
    }

    /**
     * @return string
     */
    public function get(): string
    {
        DB::transaction(function () {
            while ($this->handleChunk()) ;
            $this->chunkPos = 0;
            while ($this->handleCategoryChunk()) ;
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
                $chunk [] = [
                    'id' => $row->id,
                    'type' => $row->type,
                    'url' => $row->url,
                    'avg_cost' => $row->avg_cost,
                    'resName' => $row->resortName,
                    'name' => $row->name,
                    'book_counter' => $row->book_counter
                ];
            },
            ['productAttrs' => ['ksb_cid', 'id', 'type', 'url', 'avg_cost', 'name', 'book_counter', 'ksb_code', 'stop_sale']]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToDescrsMapping = $this->getProdIdsToDescsMapping($productIds);
        $descMap = DescriptionsConfig::getDescMap();
        $prodTypesToDescKeysMapping = [
            OBJ_PAGE_TYPE => $descMap['complex'],
            EXC_PAGE_TYPE => $descMap['exc_program'],
            ACT_PAGE_TYPE => $descMap['act_program'],
            CRU_PAGE_TYPE => $descMap['cru_route_desc'],
            CAMP_PAGE_TYPE => $descMap['complex'],
        ];
        $prodIdsToCrossListsMapping = $this->getProdIdsToCrossListsMapping($productIds);
        $prodIdsToFeedSystemsMapping = $this->getProdIdsToFeedsMappingBySystemId($productIds);
        $prodIdsToFeedTypesMapping = $this->getProdIdsToFeedsMappingByTypeId($productIds);
        $prodIdsToHotelTypesMapping = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('hotel_type'),
            ['listAttributes' => ['name']]
        );
        $prodIdsToRestTypesMapping = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('rest_types'),
            ['listAttributes' => ['name']]
        );
        $prodIdsToToursMapping = $this->getProdIdsToToursMapping($productIds);

        foreach ($chunk as &$it) {
            $tour = $prodIdsToToursMapping[$it['id']] ?? null;
            if ($tour) {
                $picUrl = $prodIdsToPicsMapping[$it['id']][0]['thumbs'][config('gallery.size.gateways_product')] ?? null;
                $pic = $picUrl ? 'https:' . $picUrl : null;
                $it['pic'] = $pic;
                $it['description'] = $prodIdsToDescrsMapping[$it['id']][$prodTypesToDescKeysMapping[$it['type']]]
                    ?? null;
                $it['paramType'] = $this->parseParamType($prodIdsToHotelTypesMapping[$it['id']] ?? null);
                $it['facilities'] = $prodIdsToCrossListsMapping[$it['id']] ?? null;
                $it['feedSystems'] = $prodIdsToFeedSystemsMapping[$it['id']] ?? null;
                $it['feedTypes'] = $prodIdsToFeedTypesMapping[$it['id']] ?? null;
                $it['paramRestType'] = $prodIdsToRestTypesMapping[$it['id']] ?? null;

                $this->result->add($it);
            }
        }

        $this->chunkPos += $this->chunkSize;

        return true;
    }

    /**
     * @param array|null $crossData
     * @return string|null
     */
    protected function parseParamType(?array $crossData): ?string
    {
        if ($crossData) {
            $keys = array_keys($crossData);
            return $keys ? $crossData[$keys[0]]->list->name : null;
        }
        return null;
    }

    /**
     * @return string
     */
    protected function convertToRequiredFormat(): string
    {
        $filePath = Path::join(storage_path('etc'), 'mindbox-yml-extended.xml');
        File::put($filePath, '');

        $xmlWriter = new XMLWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument('1.0', 'UTF-8');

        $xmlWriter->startElement('yml_catalog');
        $xmlWriter->writeAttribute('date', date('Y-m-d H:i'));

        $xmlWriter->startElement('shop');

        foreach ($this->getCredentials() as $k => $v) {
            $xmlWriter->writeElement($k, $v);
        }

        $xmlWriter->startElement('offers');

        $counter = 0;
        $writeParam = function (XMLWriter &$xmlWriter, string $name, string $param): void {
            $xmlWriter->startElement('param');
            $xmlWriter->writeAttribute('name', $name);
            $xmlWriter->text($param);
            $xmlWriter->endElement(); # param
        };
        while ($it = $this->result->getNext()) {
            $xmlWriter->startElement('offer');
            $xmlWriter->writeAttribute('id', $it['id']);
            $xmlWriter->writeAttribute('available', 'true');

            !isset($it['resId']) ?: $xmlWriter->writeElement('categoryId', $it['resId']);

            $url = Enjoin::get('Pages')->getUrl((string)$it['url'], $it['type'], $it['id']);
            $xmlWriter->writeElement('url', $this->addDomainToUrl($url));

            !isset($it['pic']) ?: $xmlWriter->writeElement('picture', $it['pic']);

            !isset($it['avg_cost']) ?: $xmlWriter->writeElement('price', round($it['avg_cost'] * 0.8, 0));

            $xmlWriter->writeElement('name', $it['name']);

            !isset($it['book_counter']) ?: $xmlWriter->writeElement('bookCounter', $it['book_counter']);

            !isset($it['description']) ?: $xmlWriter->writeElement('description', '<![CDATA[' . $it['description'] . ']]>');

            !isset($it['paramType']) ?: $writeParam($xmlWriter, 'type', $it['paramType']);

            $writeParam($xmlWriter, 'destination', $it['resName']);

            if ($it['facilities']) {
                $listMap = ListsConfig::getListMap();
                if (isset($it['facilities'][$listMap['children_animation']])) {
                    $writeParam($xmlWriter, 'Детская анимация', 'Да');
                }
                if (isset($it['facilities'][$listMap['karaoke']])) {
                    $writeParam($xmlWriter, 'Караоке', 'Да');
                }
                if (isset($it['facilities'][$listMap['night_club']])) {
                    $writeParam($xmlWriter, 'Ночной клуб', 'Да');
                }
                if (isset($it['facilities'][$listMap['bowling']])) {
                    $writeParam($xmlWriter, 'Боулинг', 'Да');
                }
                if (isset($it['facilities'][$listMap['aquapark']])) {
                    $writeParam($xmlWriter, 'Аквапарк', 'Да');
                }
                if (isset($it['facilities'][$listMap['outdoor_pool']])) {
                    if (isset($it['facilities'][$listMap['heated_pool']])) {
                        $writeParam($xmlWriter, 'Подогреваемый бассейн', 'Да');
                    } else {
                        $writeParam($xmlWriter, 'Открытый бассейн', 'Да');
                    }
                }
                if (isset($it['facilities'][$listMap['indoor_pool']])) {
                    $writeParam($xmlWriter, 'Крытый бассейн', 'Да');
                }
                if (isset($it['facilities'][$listMap['private_beach']])) {
                    $writeParam($xmlWriter, 'Собственный пляж', 'Да');
                } elseif (
                    isset($it['facilities'][$listMap['unequipped_beach']])
                    || isset($it['facilities'][$listMap['equipped_beach']])
                    || isset($it['facilities'][$listMap['beach_distance']])) {
                    $writeParam($xmlWriter, 'Пляж', 'Да');
                }
                if (isset($it['facilities'][$listMap['common_wifi']])) {
                    $writeParam($xmlWriter, 'WI-FI', 'Да');
                }
                if (isset($it['facilities'][$listMap['spa']])) {
                    $writeParam($xmlWriter, 'СПА-центр', 'Да');
                }
                if (isset($it['facilities'][$listMap['sea_distance']])) {
                    $writeParam($xmlWriter, 'Расстояние до моря', (int)$it['facilities'][$listMap['sea_distance']]);
                }
                if (isset($it['facilities'][$listMap['pump_room_distance']])) {
                    $writeParam($xmlWriter, 'Расстояние до бювета', (int)$it['facilities'][$listMap['pump_room_distance']]);
                }
                if (isset($it['facilities'][$listMap['downtown_distance']])) {
                    $writeParam($xmlWriter, 'Расстояние до центра', (int)$it['facilities'][$listMap['downtown_distance']]);
                }
                if (isset($it['facilities'][$listMap['elevator_distance']])) {
                    $writeParam($xmlWriter, 'Расстояние до подъемников', (int)$it['facilities'][$listMap['elevator_distance']]);
                }
            }

            $feedParam = false;
            if ($it['feedSystems']) {
                foreach ($this->feedSystems as $id) {
                    if (isset($it['feedSystems'][$id])) {
                        $feedParam = true;
                        $writeParam($xmlWriter, 'Питание', 'Все включено');
                        break;
                    }
                }
            }
            if (!$feedParam && $it['feedTypes']) {
                foreach ($this->feedTypeIds as $id) {
                    if (isset($it['feedTypes'][$id])) {
                        $writeParam($xmlWriter, 'Питание', 'Шведский стол');
                        break;
                    }
                }
            }
            if ($it['paramRestType']) {
                foreach ($it['paramRestType'] as $restType) {
                    $writeParam($xmlWriter, $restType->list->name, 'Да');
                }
            }

            $xmlWriter->endElement(); # offer
            ++$counter;
            if ($counter >= $this->flushCounter) {
                $counter = 0;
                File::append($filePath, $xmlWriter->flush(true));
            }
        }
        $xmlWriter->endElement(); # offers

        $xmlWriter->startElement('categories');
        $this->saveCategories($xmlWriter);
        $xmlWriter->endElement(); # categories
        $xmlWriter->endElement(); # shop
        $xmlWriter->endElement(); # yml_catalog
        $xmlWriter->endDocument();

        File::append($filePath, $xmlWriter->flush(true));

        return $filePath;
    }

    /**
     * @return array
     */
    protected function getCredentials(): array
    {
        return [
            'name' => 'Алеан',
            'company' => 'ГК РВБ Алеан',
            'url' => env('APP_URL'),
            'email' => 'webmaster@alean.ru'
        ];
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
