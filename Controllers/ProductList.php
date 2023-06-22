<?php

namespace Bundles\Gateways\ProductList\Controllers;

use Bundles\Gateways\Std\Controllers\Gateways;
use Bundles\Gateways\Std\Lib\Gateways as Gateway;
use Illuminate\Http\Request;
use Bundles\Gateways\ProductList\Lib\FacebookFeed;
use Bundles\Gateways\ProductList\Lib\MindboxYmlFeed;
use Bundles\Gateways\ProductList\Lib\MindboxYmlExtended;
use Bundles\Gateways\ProductList\Lib\YandexContextFeed;
use Bundles\Gateways\ProductList\Lib\GoogleContextFeed;
use Bundles\Gateways\ProductList\Lib\ProductResultsSwapping;
use Bundles\Gateways\ProductList\Lib\YandexHotelsRetargetingFeed;
use Bundles\Gateways\ProductList\Lib\GoogleHotelsRetargetingFeed;
use Bundles\Gateways\ProductList\Lib\K50ymlFeed;
use Bundles\Gateways\ProductList\Lib\TheWorkPeriodFeed;
use Exception, Cache, CacheTtl;

class ProductList extends Gateways
{

    const SECRET_KEY = 'somesecret';

    protected $feedsTags = ['Bundles.Gateways.ProductList.Controllers.ProductList'];

    protected $cacheTtl = 180; # minutes

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response
     */
    public function getFacebook(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'facebook-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new FacebookFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::CSV_FORMAT);
        }
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function getMindboxYml()
    {
        try {
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'mindbox-yml-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new MindboxYmlFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::XML_FORMAT);
        }
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function getMindboxYmlExtended()
    {
        try {
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'mindbox-yml-extended-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new MindboxYmlExtended(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::XML_FORMAT);
        }
    }

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function getYandexContext(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->download(
                Cache::tags($this->feedsTags)->remember(
                    'yandex-context-up-to-date-' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new YandexContextFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::XML_FORMAT);
        }
    }

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function getGoogleContext(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->download(
                Cache::tags($this->feedsTags)->remember(
                    'google-context-up-to-date-' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new GoogleContextFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::XML_FORMAT);
        }
    }

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response
     */
    public function getYandexHotelsRetargeting(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'yandex-hotels-retargeting-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new YandexHotelsRetargetingFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::CSV_FORMAT);
        }
    }

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response
     */
    public function getGoogleHotelsRetargeting(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'google-hotels-retargeting-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new GoogleHotelsRetargetingFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::CSV_FORMAT);
        }
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function k50yml()
    {
        try {
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'k50-yml-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new K50ymlFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::XML_FORMAT);
        }
    }

    /**
     * @param Request $req
     * @return \Illuminate\Http\Response
     */
    public function workPeriodFeed(Request $req)
    {
        try {
            $this->checkSecret($req->get('key'));
            return response()->file(
                Cache::tags($this->feedsTags)->remember(
                    'work-period-feed-up-to-date' . env('APP_ENV'),
                    CacheTtl::inMinutes($this->cacheTtl),
                    function () {
                        return (new TheWorkPeriodFeed(new ProductResultsSwapping()))->get();
                    }
                )
            );
        } catch (\Exception $e) {
            return $this->sendError($e, Gateway::CSV_FORMAT);
        }
    }

    /**
     * @param string $key
     * @throws \Exception
     */
    protected function checkSecret(string $key): void
    {
        if ($key != self::SECRET_KEY) {
            throw new Exception('Invalid key!');
        }
    }

}
