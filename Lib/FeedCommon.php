<?php

namespace Bundles\Gateways\ProductList\Lib;

use Enjoin, DescriptionsConfig, ListsConfig, DB, Pile, Closure, ZipArchive, XMLWriter;

class FeedCommon
{

    protected $chunkPos = 0;
    protected $chunkSize = 500;

    protected $regionsMaxDepth = 3;

    protected $descriptionsIds = [];

    protected $crossListsId = [];

    protected $feedSystems = [];

    protected $feedTypeIds = [];

    protected $options = [
        'productIdsChunkSize' => 500,
    ];

    protected $result = null;

    protected $categories = [];

    protected $csvColumnHeaders = [];

    /**
     * FeedCommon constructor.
     * @param ProductResultsContract $resultsVault
     */
    public function __construct(ProductResultsContract $resultsVault)
    {
        $this->descriptionsIds = [
            DescriptionsConfig::getDescMap('anons'),
            DescriptionsConfig::getDescMap('complex'),
            DescriptionsConfig::getDescMap('advantages'),
        ];
        $this->crossListsId = [
            ListsConfig::getListMap('1star'),
            ListsConfig::getListMap('2star'),
            ListsConfig::getListMap('3star'),
            ListsConfig::getListMap('4star'),
            ListsConfig::getListMap('5star'),
            ListsConfig::getListMap('children_animation'),
            ListsConfig::getListMap('karaoke'),
            ListsConfig::getListMap('night_club'),
            ListsConfig::getListMap('bowling'),
            ListsConfig::getListMap('animation'),
            ListsConfig::getListMap('heated_pool'),
            ListsConfig::getListMap('outdoor_pool'),
            ListsConfig::getListMap('indoor_pool'),
            ListsConfig::getListMap('unequipped_beach'),
            ListsConfig::getListMap('equipped_beach'),
            ListsConfig::getListMap('beach_distance'),
            ListsConfig::getListMap('private_beach'),
            ListsConfig::getListMap('common_wifi'),
            ListsConfig::getListMap('spa'),
            ListsConfig::getListMap('sea_distance'),
            ListsConfig::getListMap('pump_room_distance'),
            ListsConfig::getListMap('elevator_distance'),
            ListsConfig::getListMap('downtown_distance'),
            ListsConfig::getListMap('aquapark'),
        ];
        $this->feedSystems = [
            ListsConfig::getListMap('all_inclusive'),
            ListsConfig::getListMap('all_inclusive_3_times'),
            ListsConfig::getListMap('ultra_all_inclusive'),
            ListsConfig::getListMap('ultra_all_inclusive_3_times'),
        ];
        $this->feedTypeIds = [ListsConfig::getListMap('swedish')];
        $this->result = $resultsVault;
    }

    /**
     * @return string
     */
    public function get(): string
    {
    }

    /**
     * @return bool
     */
    protected function handleChunk(): bool
    {
    }

    /**
     * @param array $productIds
     * @param array $options
     * @return array
     */
    protected function getProdIdsToToursMapping(array &$productIds, array $options = []): array
    {
        $tours = Enjoin::get('KsbTours')->findAll([
            'where' => [
                'on_state' => true,
                'code' => ['ne' => null],
            ],
            'attributes' => $options['attrs'] ?? ['id'],
            'include' => [
                'model' => Enjoin::get('PagesKsbTours'),
                'required' => true,
                'where' => [
                    'pages_id' => $productIds,
                ],
                'attributes' => ['id', 'pages_id'],
            ],
        ]);
        $out = [];
        foreach ($tours ?? [] as $tour) {
            foreach ($tour->pagesKsbTours ?? [] as $page) {
                if (isset($out[$page->pages_id])) {
                    continue;
                }
                $out[$page->pages_id] = $tour;
            }
        }
        return $out;
    }

    /**
     * @return bool
     */
    protected function handleCategoryChunk(): bool
    {
        $before = count($this->categories);
        $this->categoryIterator(
            $this->chunkPos,
            $this->chunkSize,
            function ($row) {
                $this->categories [] =
                $chunk [] = [
                    'id' => $row->id,
                    'name' => $row->name,
                    'pid' => $row->pid,
                ];
            }
        );
        if (count($this->categories) === $before) {
            return false;
        }
        $this->chunkPos += $this->chunkSize;
        return true;
    }

    /**
     * @param int $chunkPos
     * @param int $chunkSize
     * @param \Closure $handler
     * @param array $options
     * @option array 'productAttrs'
     * @option array 'productTypes'
     */
    protected function productsIterator(int $chunkPos, int $chunkSize, Closure $handler, array $options = [])
    {
        $productAttrs = $options['productAttrs'] ?? ['id', 'pid', 'type', 'name', 'url', 'ksb_cid', 'avg_cost'];
        $productAttrs = $this->getProductAttrs($productAttrs);

        $productTypes = $options['productTypes'] ?? ['obj'];
        $productTypePlaceholders = join(',', array_fill(0, count($productTypes), '?'));

        $sql = <<<EOD
SELECT 
  $productAttrs, resort.name as resortName
FROM pages AS product
JOIN pages AS resort
JOIN looks AS looks
ON product.id = looks.pages_id AND product.pid = resort.id
WHERE product.on_state = 1
  AND product.stop_sale IS NULL
  AND product.has_finder IS NOT NULL
  AND product.ksb_cid IS NOT NULL
  AND product.type IN ($productTypePlaceholders)
LIMIT $chunkPos, $chunkSize
EOD;
        Pile::fetchDbUnbuffered(
            DB::connection(),
            $sql,
            $productTypes,
            $handler
        );
    }

    /**
     * @param int $chunkPos
     * @param int $chunkSize
     * @param \Closure $handler
     * @option array 'productAttrs'
     * @option array 'productTypes'
     */
    protected function categoryIterator(int $chunkPos, int $chunkSize, Closure $handler)
    {
        $sql = <<<EOD
SELECT id, name, pid
FROM pages 
WHERE on_state IS NOT NULL
    AND type IN ('res','dir','country')
LIMIT $chunkPos, $chunkSize
EOD;
        Pile::fetchDbUnbuffered(DB::connection(), $sql, [], $handler);
    }

    /**
     * @param XMLWriter $xmlWriter
     */
    protected function saveCategories(XMLWriter $xmlWriter): void
    {
        foreach ($this->categories as $category) {
            $xmlWriter->startElement('category');
            $xmlWriter->writeAttribute('id', $category['id']);
            if ($category['pid']) {
                $xmlWriter->writeAttribute('parentId', $category['pid']);
            }
            $xmlWriter->text($category['name']);
            $xmlWriter->endElement(); # category
        }
    }

    /**
     * @param array $productIds
     * @return array
     */
    protected function getProdIdsToPicsMapping(array &$productIds): array
    {
        return Enjoin::get('Pics')->getGallery([
            'pageId' => $productIds,
            'label' => 'gallery',
            'thumbs' => [config('gallery.size.gateways_product')],
            'weight' => 0,
        ]);
    }

    /**
     * @param array $productIds
     * @return array
     */
    protected function getProdIdsToDescsMapping(array &$productIds): array
    {
        $res = Enjoin::get('CrossDescriptions')->findAll([
            'where' => [
                'pages_id' => $productIds,
                'descriptions_id' => $this->descriptionsIds
            ],
            'attributes' => ['val', 'descriptions_id', 'pages_id'],
        ]);
        $out = [];
        foreach ($res as $row) {
            if (!isset($out[$row->pages_id])) {
                $out[$row->pages_id] = [];
                $out[$row->pages_id][$row->descriptions_id] = [];
            }
            $out[$row->pages_id][$row->descriptions_id] = $row->val;
        }
        return $out;
    }

    /**
     * @param array $productIds
     * @param array $options
     * @return array
     */
    protected function getProdIdsToCrossListsMapping(array &$productIds, array $options = []): array
    {
        $crossData = Enjoin::get('CrossLists')->findAll(
            array_merge(
                [
                    'where' => [
                        'pages_id' => $productIds,
                        'lists_id' => $this->crossListsId
                    ],
                    'attributes' => ['pages_id', 'lists_id', 'val']
                ],
                isset($options['listAttributes'])
                    ? [
                        'include' => [
                            'model' => Enjoin::get('Lists'),
                            'required' => false,
                            'where' => ['on_state' => true],
                            'attributes' => $options['listAttributes'],
                        ]
                    ]
                    : []
            )
        );
        $out = [];
        foreach ($crossData ?? [] as $crossValue) {
            $out[$crossValue->pages_id][$crossValue->lists_id] = isset($options['listAttributes'])
                ? $crossValue
                : $crossValue->val ?? true;
        }
        return $out;
    }

    /**
     * @param array $productIds
     * @return array
     */
    protected function getProdIdsToFeedsMappingBySystemId(array &$productIds): array
    {
        $feeds = Enjoin::get('Feeds')->findAll([
            'where' => [
                'pages_id' => $productIds,
                'system_id' => $this->feedSystems
            ],
            'attributes' => ['pages_id', 'system_id']
        ]);
        $out = [];
        foreach ($feeds ?? [] as $feed) {
            $out[$feed->pages_id][$feed->system_id] = true;
        }
        return $out;
    }

    /**
     * @param array $productIds
     * @return array
     */
    protected function getProdIdsToFeedsMappingByTypeId(array &$productIds): array
    {
        $feeds = Enjoin::get('Feeds')->findAll([
            'where' => [
                'pages_id' => $productIds,
                'type_id' => $this->feedTypeIds
            ],
            'attributes' => ['pages_id', 'type_id']
        ]);
        $out = [];
        foreach ($feeds ?? [] as $feed) {
            $out[$feed->pages_id][$feed->type_id] = true;
        }
        return $out;
    }

    /**
     * @param array $productIds
     * @param int|null $rootId
     * @param array $options
     * @return array
     */
    protected function getProdIdsToCrossListsMappingByRoot(array &$productIds, ?int $rootId, array $options = []): array
    {
        $crossData = Enjoin::get('CrossLists')->findAll(
            array_merge(
                [
                    'where' => [
                        'pages_id' => $productIds,
                        'root_id' => $rootId
                    ],
                    'attributes' => ['pages_id', 'lists_id', 'val']
                ],
                isset($options['listAttributes'])
                    ? [
                        'include' => [
                            'model' => Enjoin::get('Lists'),
                            'required' => false,
                            'where' => ['on_state' => true],
                            'attributes' => $options['listAttributes'],
                        ]
                    ]
                    : []
            )
        );
        $out = [];
        foreach ($crossData ?? [] as $it) {
            $out[$it->pages_id][$it->lists_id] = isset($options['listAttributes'])
                ? $it
                : $it->val;
        }
        return $out;
    }

    /**
     * @param int $productId
     * @return string
     */
    protected function getUrl(int $productId): string
    {
        return env('APP_URL') . 'object/' . $productId . '/';
    }

    /**
     * @return string
     */
    protected function convertToRequiredFormat(): string
    {
    }

    /**
     * @param array $productAttrs
     * @return string
     */
    protected function getProductAttrs(array $productAttrs): string
    {
        $attrs = [];
        foreach ($productAttrs as $attr) {
            $attrs [] = "product.`{$attr}`";
        }
        return $attrs ? join(',', $attrs) : '';
    }

    /**
     * @param string $fileToZip
     * @param string $zippedFile
     */
    protected function toZip(string $fileToZip, string $zippedFile): void
    {
        $zip = new ZipArchive();
        $zip->open($zippedFile, ZIPARCHIVE::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($fileToZip, pathinfo($fileToZip, PATHINFO_BASENAME));
        $zip->close();
    }

}
