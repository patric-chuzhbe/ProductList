<?php

namespace Bundles\Gateways\ProductList\Lib;

use DescriptionsConfig, Path, File, XMLWriter, Enjoin, ListsConfig, DB;

class MindboxYmlFeed extends FeedCommon
{

    protected $flushCounter = 500;

    /**
     * MindboxYmlFeed constructor.
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
                    'name' => $row->name,
                    'resName' => $row->resortName,
                    'offerId' => $row->ksb_cid,
                    'url' => $row->url,
                    'type' => $row->type,
                    'pid' => $row->pid,
                ];
            },
            ['productAttrs' => ['id', 'name', 'ksb_cid', 'url', 'type', 'pid']]
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
        $prodIdsToHotelTypesMapping = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('hotel_type'),
            ['listAttributes' => ['name']]
        );
        foreach ($chunk as &$it) {
            $picUrl = $prodIdsToPicsMapping[$it['id']][0]['thumbs'][config('gallery.size.gateways_product')] ?? null;
            $pic = $picUrl ? 'https:' . $picUrl : null;
            $it['pic'] = $pic;
            $it['description'] = $prodIdsToDescrsMapping[$it['id']][$prodTypesToDescKeysMapping[$it['type']]]
                ?? null;
            $it['paramType'] = $this->parseParamType($prodIdsToHotelTypesMapping[$it['id']] ?? null);

            $this->result->add($it);
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
        $filePath = Path::join(storage_path('etc'), 'mindbox-yml.xml');
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
        $done = [];
        while ($it = $this->result->getNext()) {
            $xmlWriter->startElement('offer');
            $xmlWriter->writeAttribute('id', $it['offerId']);
            $xmlWriter->writeAttribute('available', 'true');

            $xmlWriter->writeElement('categoryId', $it['pid']);

            $url = Enjoin::get('Pages')->getUrl((string)$it['url'], $it['type'], $it['id']);
            $xmlWriter->writeElement('url', $this->addDomainToUrl($url));

            !isset($it['pic']) ?: $xmlWriter->writeElement('picture', $it['pic']);

            $xmlWriter->writeElement('name', $it['name']);

            if ($it['description']) {
                $xmlWriter->writeElement('description', $it['description']);
            }

            if ($it['paramType']) {
                $xmlWriter->startElement('param');
                $xmlWriter->writeAttribute('name', 'type');
                $xmlWriter->text($it['paramType']);
                $xmlWriter->endElement(); # param
            }

            $xmlWriter->startElement('param');
            $xmlWriter->writeAttribute('name', 'destination');
            $xmlWriter->text($it['resName']);
            $xmlWriter->endElement(); # param

            $xmlWriter->endElement(); # offer

            $done[$it['id']] = true;
            $counter++;
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
