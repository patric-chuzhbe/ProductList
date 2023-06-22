<?php

return [
    [
        'method' => 'get',
        'uri' => 'gateways/feed/facebook',
        'uses' => 'ProductList@getFacebook',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/mindbox/yml',
        'uses' => 'ProductList@getMindboxYml',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/mindbox/ymlExtended',
        'uses' => 'ProductList@getMindboxYmlExtended',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/context/yandex',
        'uses' => 'ProductList@getYandexContext',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/context/google',
        'uses' => 'ProductList@getGoogleContext',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/yandex/hotels/retargeting',
        'uses' => 'ProductList@getYandexHotelsRetargeting',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/google/hotels/retargeting',
        'uses' => 'ProductList@getGoogleHotelsRetargeting',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/aleank50.yml',
        'uses' => 'ProductList@k50yml',
    ],
    [
        'method' => 'get',
        'uri' => 'gateways/feed/work-period-feed',
        'uses' => 'ProductList@workPeriodFeed',
    ],
];
