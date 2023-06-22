<?php

namespace Bundles\Gateways\ProductList\Lib;

use Path, File, XMLWriter, ListsConfig, DB;

class K50ymlFeed extends FeedCommon
{

    protected $flushCounter = 100;

    /**
     * K50ymlFeed constructor.
     * @param ProductResultsContract $resultsVault
     */
    public function __construct(ProductResultsContract $resultsVault)
    {
        parent::__construct($resultsVault);
        $this->crossListsId = [
            ListsConfig::getListMap('spa'),
            ListsConfig::getListMap('common_wifi'),
            ListsConfig::getListMap('parking'),
            ListsConfig::getListMap('outdoor_pool'),
            ListsConfig::getListMap('indoor_pool'),
            ListsConfig::getListMap('private_beach'),
            ListsConfig::getListMap('sea_distance'),
            ListsConfig::getListMap('beach_distance'),
            ListsConfig::getListMap('elevator_distance'),
            ListsConfig::getListMap('downtown_distance'),
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
                $chunk [] = [
                    'id' => $row->id,
                    'url' => $row->url,
                    'price' => $row->avg_cost,
                    'name_object' => $row->name,
                    'hotel_rating' => $row->rating
                ];
            },
            [
                'productAttrs' => ['id', 'url', 'avg_cost', 'name', 'rating'],
                'productTypes' => [OBJ_PAGE_TYPE]
            ]
        );
        if (!count($chunk)) {
            return false;
        }
        $prodIdsToHotelTypesMapping = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('hotel_type'),
            ['listAttributes' => ['name']]
        );
        $prodIdsToPicsMapping = $this->getProdIdsToPicsMapping($productIds);
        $prodIdsToCrossListsMapping = $this->getProdIdsToCrossListsMapping($productIds);
        $prodIdsToTreatmentProfiles = $this->getProdIdsToCrossListsMappingByRoot(
            $productIds,
            ListsConfig::getRootMap('treatment_profiles'),
            ['listAttributes' => ['name']]
        );
        $prodIdsToToursMapping = $this->getProdIdsToToursMapping($productIds);
        foreach ($chunk as &$it) {
            $tour = $prodIdsToToursMapping[$it['id']] ?? null;
            if ($tour) {

                $normalizeName = $this->normalize($it['name_object']);
                $it['name_rus'] = $this->toRussian($normalizeName);

                $it['object_type'] = $this->parseParamType($prodIdsToHotelTypesMapping[$it['id']] ?? null)
                    ?? $this->parseObjectType($normalizeName);

                # Handle picture:
                $picUrl = $prodIdsToPicsMapping[$it['id']][0]['thumbs'][config('gallery.size.gateways_product')] ?? null;
                $it['picture'] = $picUrl ? 'https:' . $picUrl : null;

                # Handle facilities:
                $facilities = $prodIdsToCrossListsMapping[$it['id']] ?? null;
                $it['services_spa'] = isset($facilities[ListsConfig::getListMap('spa')]);
                $it['services_wifi'] = isset($facilities[ListsConfig::getListMap('common_wifi')]);
                $it['services_parking'] = isset($facilities[ListsConfig::getListMap('parking')]);
                $it['services_open_pool'] = isset($facilities[ListsConfig::getListMap('outdoor_pool')]);
                $it['services_closed_pool'] = isset($facilities[ListsConfig::getListMap('indoor_pool')]);
                $it['services_treatment'] = isset($prodIdsToTreatmentProfiles[$it['id']]);
                $it['services_beach'] = isset($facilities[ListsConfig::getListMap('private_beach')]);

                $it['distance_to_sea'] = $this->parseDistance($facilities[ListsConfig::getListMap('sea_distance')] ?? false);
                $it['distance_to_the_beach'] = $this->parseDistance($facilities[ListsConfig::getListMap('beach_distance')] ?? false);
                $it['distance_to_lift'] = $this->parseDistance($facilities[ListsConfig::getListMap('elevator_distance')] ?? false);
                $it['distance_to_center'] = $this->parseDistance($facilities[ListsConfig::getListMap('downtown_distance')] ?? false);

                $this->result->add($it);
            }
        }

        $this->chunkPos += $this->chunkSize;

        return true;
    }

    /**
     * @param string $string
     * @return string
     */
    protected function normalize(string $string) : string
    {
        $pattern = [
            '/(\(.+\))/ums'
        ];

        $replacement = [
            ''
        ];

        return preg_replace($pattern, $replacement, $string);
    }

    /**
     * @param $string
     * @return string|null
     */
    protected function toRussian($string) : ?string
    {
        $pattern = '/«(?<names>.+?)»/ms';
        preg_match_all($pattern, $string, $matches);
        $names = $matches['names'];

        if (empty($names)) {
            return null;
        }

        if (count($names) == 1) {
            $name = array_shift($names);
            $names = explode('/', $name);
            if (count($names) == 1) {
                return $name;
            }
            return $this->getRussianName($names);
        }
        return $this->getRussianName($names);
    }

    /**
     * @param array $names
     * @return string
     */
    protected function getRussianName(array $names) : string
    {
        usort($names, array($this, 'compareString'));
        return array_shift($names);
    }

    /**
     * @param string $a
     * @param string $b
     * @return int
     */
    protected function compareString(string $a, string $b) : int {
        return $this->getStringIndex($a) - $this->getStringIndex($b);
    }

    /**
     * @param $string
     * @return int
     */
    protected function getStringIndex($string) : int
    {
        $ascii = array_keys(count_chars($string, 1));
        return (in_array('208', $ascii) || in_array('209', $ascii))? -1 : 1;
    }

    /**
     * @param string $string
     * @return string|null
     */
    protected function parseObjectType(string $string) : ?string
    {
        $pattern = '/.+»(?<objectType>[^(|^,\n]*)/ms';
        if ($result = preg_match($pattern, $string, $matches)) {
            $str = trim($matches['objectType']);
            return ($str == "")? null : $str;
        }
        return null;
    }

    /**
     * @param $string
     * @return string|null
     */
    protected function parseDistance($string) : ?string
    {
        if (is_bool($string)) return null;

        $pattern = '/(?<distance>\d+[,|.]?\d*)\s*(?<measure>к?м)/ums';
        if (preg_match($pattern, $string, $matches)) {
            return $matches['distance'] . ' ' .$matches['measure'];
        }

        return null;
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
        $filePath = Path::join(storage_path('etc'), 'k50-yml.xml');
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
        while ($it = $this->result->getNext()) {
            $xmlWriter->startElement('offer');
            $xmlWriter->writeElement('id', $it['id']);
            $xmlWriter->writeElement('name_object', $it['name_object']);
            $xmlWriter->writeElement('name_rus', $it['name_rus'] ?? 'null');
            $xmlWriter->writeElement('url', $this->addDomainToUrl($it['url']));
            $xmlWriter->writeElement('price', is_null($it['price'])? 'null' : round($it['price']));
            $xmlWriter->writeElement('object_type', is_null($it['object_type'])? 'null' : $it['object_type']);
            $xmlWriter->writeElement('picture', is_null($it['picture'])? 'null' : $it['picture']);
            $xmlWriter->writeElement('hotel_rating', $it['hotel_rating'] ?? 'null');
            $xmlWriter->writeElement('services_spa', $it['services_spa'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_wifi', $it['services_wifi'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_parking', $it['services_parking'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_open_pool', $it['services_open_pool'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_closed_pool', $it['services_closed_pool'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_treatment', $it['services_treatment'] ? 'true' : 'false');
            $xmlWriter->writeElement('services_beach', $it['services_beach'] ? 'true' : 'false');
            $xmlWriter->writeElement('distance_to_sea', $it['distance_to_sea'] ?? 'null');
            $xmlWriter->writeElement('distance_to_the_beach', $it['distance_to_the_beach'] ?? 'null');
            $xmlWriter->writeElement('distance_to_lift', $it['distance_to_lift'] ?? 'null');
            $xmlWriter->writeElement('distance_to_center', $it['distance_to_center'] ?? 'null');

            $xmlWriter->endElement(); # offer
            ++$counter;
            if ($counter >= $this->flushCounter) {
                $counter = 0;
                File::append($filePath, $xmlWriter->flush(true));
            }
        }
        $xmlWriter->endElement(); # offers

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
