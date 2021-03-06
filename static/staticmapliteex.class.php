<?php

error_reporting(E_ALL & ~E_NOTICE);

/**
 * staticMapLite 0.03
 * Copyright 2009 Gerhard Koch
 *
 * staticMapLiteEx 0.04
 * Copyright 2013 Jan Martinec
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 * @author Jan "Piskvor" Martinec <staticMapLiteEx AT martinec.name>
 *
 * USAGE:
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=mapnik&markers=40.702147,-74.015794,blues|40.711614,-74.012318,greeng|40.718217,-73.998284,redc
 *
 */
class staticMapLiteEx {

    protected $tileSize = 256; // most tileservers use 256; don't change this if you are not absolutely certain what it does
    protected $tileSrcUrl = array(
        'mapnik' => 'http://www.server2.oregon911.net/osm/{Z}/{X}/{Y}.png' // the "usual" OpenStreetMap tiles
    );
    protected $ua = 'PHP/staticMapLiteEx 1.0'; // default User-Agent
    protected $tileDefaultSrc;
    protected $markerBaseDir = '../images/'; // directory containing markers
    protected $osmLogo = 'images/osm_logo.png'; // OSM logo overlay
    protected $or911Logo = 'images/oregon911_logo.png'; // oregon 911 logo
    protected $markerPrototypes = array(// found at http://www.mapito.net/map-marker-icons.html
        'lighblue' => array('regex' => '/^lightblue([0-9]+)$/',
            'extension' => '.png',
            'shadow' => false,
            'offsetImage' => '0,-19',
            'offsetShadow' => false
        ),
        // openlayers standard markers
        'ol-marker' => array('regex' => '/^ol-marker(|-red|-blue|-gold|-green)+$/',
            'extension' => '.png',
            'transparent' => true,
            'shadow' => '../marker_shadow.png',
            'offsetImage' => '-10,-25',
            'offsetShadow' => '-1,-13'
        ),
        // taken from http://www.visual-case.it/cgi-bin/vc/GMapsIcons.pl
        'ylw' => array('regex' => '/^(pink|purple|red|ltblu|ylw)-pushpin$/',
            'extension' => '.png',
            'shadow' => '../marker_shadow.png',
            'offsetImage' => '-10,-32',
            'offsetShadow' => '-1,-13'
        )
    );
    protected $useTileCache = false; // cache tiles instead of always loading from tile servers - cached tiles might get stale in the horizon of months
    protected $tileCacheBaseDir = 'cache/tiles'; // tile cache main directory
    protected $useMapCache = false; // cache resulting maps (with markers!) instead of always regenerating from tiles
    protected $mapCacheBaseDir = 'cache/maps'; // maps cache main directory
    protected $useHTTPCache = true; // cache image in browser, using HTTP caching headers
    protected $expireDays = 14; // days to keep image as fresh, via Expires header
    protected $scale = 1;
    protected $format = 'png';
    protected $supportedFormats = array(
        'png' => 'png',
        'jpeg' => 'jpg',
        'gif' => 'gif'
    );
    protected $mapCacheID = '';
    protected $mapCacheFile = '';
    protected $mapCacheExtension = 'png'; // currently the only supported filetype is PNG; .png is its usual file extension
    protected $zoom; // see http://wiki.openstreetmap.org/wiki/Zoom_levels
    protected $lat, $lon, $width, $height, $image, $maptype;
    protected $markers, $markerBox, $minZoom, $maxZoom;
    protected $centerX, $centerY, $offsetX, $offsetY;

    /** @throws staticMapLiteException */
    public function __construct($config = null) {
// "no config, just give me the defaults"
        if (!$config) {
            $config = array();
        }
// bail if we can't fetch HTTP resources
        if (!$this->checkCurlFunctions()) {
            throw new staticMapLiteException('Required library not loaded: curl');
        }
// bail if we can't work with images
        if (!$this->checkGdFunctions()) {
            throw new staticMapLiteException('Required library not loaded: gd');
        }
        $this->zoom = 0;
        $this->lat = 0;
        $this->lon = 0;
        $this->width = 500;
        $this->height = 350;
        $this->markers = array();

        if (!array_key_exists('request', $config)) {
            $config['request'] = $_GET;
        }
        if (!array_key_exists('requestHeaders', $config)) {
            $config['requestHeaders'] = $_SERVER;
        }
        $this->request = $config['request']; // this is usually $_GET
        $this->requestHeaders = $config['headers']; // this is usually $_SERVER
// set map sources
        if (array_key_exists('mapSources', $config)) {
            $this->tileSrcUrl = $config['mapSources'];
        }
// set User-Agent
        if (array_key_exists('ua', $config)) {
            $this->ua = $config['ua'];
        }

// min/max zoom to use for auto-zooming
        if (array_key_exists('minZoom', $config)) {
            $this->minZoom = $config['minZoom'];
        } else {
            $this->minZoom = 1;
        }
        if (array_key_exists('maxZoom', $config)) {
            $this->maxZoom = $config['maxZoom'];
        } else {
            $this->maxZoom = 18;
        }
// configure various caching options
        if (array_key_exists('cache', $config)) {
            $cache = $config['cache'];
            if (array_key_exists('http', $cache)) {
                $this->useHTTPCache = (boolean) $cache['http'];
            }
            if (array_key_exists('tile', $cache)) {
                $this->useTileCache = (boolean) $cache['tile'];
            }
            if (array_key_exists('map', $cache)) {
                $this->useMapCache = (boolean) $cache['map'];
            }
        }

// set the first source to be the default
        $sources = array_keys($this->tileSrcUrl);
        $this->tileDefaultSrc = $sources[0];
        $this->maptype = $this->tileDefaultSrc;
    }

    public function parseParams() {

        if (@$this->request['scale'] && ($this->request['scale'] == 2 || $this->request['scale'] == 4)) {
            $this->scale = (int) $this->request['scale'];
        }

        if (@$this->request['format']) {
            $format = strtolower($this->request['format']);
            if ($format == 'jpg') {
                $format = 'jpeg';
            }
            if (array_key_exists($format, $this->supportedFormats)) {
                $this->format = $format;
                $this->mapCacheExtension = $this->supportedFormats[$format];
            }
        }

// get size from request
        if (@$this->request['size']) {
            list($this->width, $this->height) = explode('x', $this->request['size']);
            $this->width = intval($this->width);
            $this->height = intval($this->height);
        }

// get markers
        if (@$this->request['markers']) {

            /*
             *  using multiple keys with the same name in query string is permitted, but regrettable:
             *  we will only get the last one into $_GET
             *  so we need to parse QS manually
             */
// first, a quick check
            $markersPosition1 = strpos($this->requestHeaders['QUERY_STRING'], 'markers'); // there needs to be one at least
            $markersPosition2 = strpos($this->requestHeaders['QUERY_STRING'], 'markers', $markersPosition1 + 1);

// if we have $markersPosition2, it means that we need to parse for multiple "marker=foobar" locations in QS
            $kvpairs = array();
            if ($markersPosition2 !== false) {
                $qsParts = explode('&', $this->requestHeaders['QUERY_STRING']);
                foreach ($qsParts as $qsp) {
                    list($key, $value) = explode('=', $qsp);
                    if (!array_key_exists($key, $kvpairs)) {
                        $kvpairs[$key] = array();
                    }
                    $kvpairs[$key][] = $value;
                }
            }
            if (count($kvpairs) > 0 && count($kvpairs['markers']) > 1) {
// multiple sets of markers
                $markerSets = $kvpairs['markers'];
            } else {
// one set only, use the default from request
                $markerSets = array($this->request['markers']);
            }

            $this->markerBox = array(
                'lat' => array(
                    'min' => PHP_INT_MAX,
                    'max' => -PHP_INT_MAX,
                ),
                'lon' => array(
                    'min' => PHP_INT_MAX,
                    'max' => -PHP_INT_MAX,
                )
            );

            foreach ($markerSets as $markerSet) {

                $markers = preg_split('/%7C|\|/', $markerSet);

// reset between markers
                $markerParams = array(
                    'color' => null,
                    'transparent' => false,
                    'size' => null,
                    'letter' => null,
                );

// from now on, we can pretend there was always just one set of markers.
                foreach ($markers as $marker) {
                    list($markerLat, $markerLon, $markerImage) = preg_split('/,|%2C/', $marker);
                    $markerLat = floatval($markerLat);
                    $markerLon = floatval($markerLon);
                    if (($markerLat == $markerLon) && ($markerLat == 0)) {
// this is not a marker at all, this sets other params (size/letter/color)
                        list($param, $paramValue) = preg_split('/:|%3A/', $marker);
                        if (array_key_exists($param, $markerParams)) {
                            $markerParams[$param] = $paramValue;
                        }
                        continue;
                    }

// get minimum/maximum for all the markers
                    if ($this->markerBox['lat']['min'] > $markerLat) {
                        $this->markerBox['lat']['min'] = $markerLat;
                    }
                    if ($this->markerBox['lat']['max'] < $markerLat) {
                        $this->markerBox['lat']['max'] = $markerLat;
                    }
                    if ($this->markerBox['lon']['min'] > $markerLon) {
                        $this->markerBox['lon']['min'] = $markerLon;
                    }
                    if ($this->markerBox['lon']['max'] < $markerLon) {
                        $this->markerBox['lon']['max'] = $markerLon;
                    }

// set basic data
                    $markerData = array(
                        'lat' => $markerLat,
                        'lon' => $markerLon,
                        'type' => $markerImage
                    );

// fixes the N/S and W/E marker overlap issues
                    $markerKey = str_pad(str_pad($markerLat, 11, '0', STR_PAD_RIGHT), 12, '0', STR_PAD_LEFT) . (180 - $markerLon) . $markerImage;
                    $this->markers[$markerKey] = $markerData;
                }
            }
// these are useful for auto-zoom
            $this->markerBox['lat']['center'] = ($this->markerBox['lat']['min'] + $this->markerBox['lat']['max']) / 2;
            $this->markerBox['lon']['center'] = ($this->markerBox['lon']['min'] + $this->markerBox['lon']['max']) / 2;
            $this->markerBox['lat']['size'] = $this->markerBox['lat']['max'] - $this->markerBox['lat']['min'];
            $this->markerBox['lon']['size'] = $this->markerBox['lon']['max'] - $this->markerBox['lon']['min'];

// together with the array keys, this ensures that southernmost keys are the last (therefore on top)
            krsort($this->markers);
//var_dump($this->markers);
        }

        if (@$this->request['center']) {
// get lat and lon from request
            list($this->lat, $this->lon) = explode(',', $this->request['center']);
            $this->lat = floatval($this->lat);
            $this->lon = floatval($this->lon);
        } else if (count($this->markers) && $this->minZoom !== null && $this->maxZoom !== null && $this->minZoom <= $this->maxZoom) {
// if we have markers but not center, find the center and zoom from marker position(s)
            list($this->lat, $this->lon, $this->zoom) = $this->getCenterFromMarkers($this->markerBox, $this->width - 20, $this->height - 20, $this->maxZoom, $this->minZoom);
        }

// get zoom from request
        $this->zoom = @$this->request['zoom'] ? intval($this->request['zoom']) : $this->zoom;
// set maximum zoom
        if ($this->zoom > 18) {
            $this->zoom = 18;
        }

// set map type
        if (@$this->request['maptype']) {
            if (array_key_exists($this->request['maptype'], $this->tileSrcUrl))
                $this->maptype = $this->request['maptype'];
        }

        $this->mapCacheID = md5($this->serializeParams());
    }

    protected function getCenterFromMarkers($markerBox, $width, $height, $maxZoom, $minZoom) {
        /*
          // DEBUG: uncomment the above to show marker box on map
          $this->markers[] = array(
          'lat' => $markerBox['lat']['center'],
          'lon' => $markerBox['lon']['center'],
          'image' => 'ol-marker-green',
          );

          $this->markers[] = array(
          'lat' => $markerBox['lat']['max'],
          'lon' => $markerBox['lon']['max'],
          'image' => 'ol-marker-green',
          );

          $this->markers[] = array(
          'lat' => $markerBox['lat']['min'],
          'lon' => $markerBox['lon']['min'],
          'image' => 'ol-marker-green',
          );
          // */

// start from $maxZoom and work outwards from there
        $zoom = $maxZoom;
// for latitude, we need to correct (otherwise calculation would be correct on the equator only) - see http://wiki.openstreetmap.org/wiki/File:Tissot_mercator.png
        $latCorrection = 360 * cos(deg2rad($this->markerBox['lat']['center']));

        for (; $zoom >= $minZoom; $zoom--) {
// how many degrees wide is the image? - longitude doesn't need correction
            $degreesWidth = $width * 360 / (pow(2, ($zoom + 8)));

// how many degrees high is the image? - apply the latitude correction from above
            $latDegreesPerPixel = $latCorrection / (pow(2, ($zoom + 8)));
            $degreesHeight = $latDegreesPerPixel * $height;

            /*
              // DEBUG: uncomment the above to show zoom levels on map
              $this->markers[] = array(
              'lat' => $markerBox['lat']['center'] + $degreesHeight,
              'lon' => $markerBox['lon']['center'] + $degreesWidth,
              'image' => 'ol-marker-gold',
              );
              $this->markers[] = array(
              'lat' => $markerBox['lat']['center'] - $degreesHeight,
              'lon' => $markerBox['lon']['center'] - $degreesWidth,
              'image' => 'ol-marker-gold',
              );
              // */

            if ($degreesWidth >= $markerBox['lon']['size'] && $degreesHeight >= $markerBox['lat']['size']) {
// in this case, all markers will fit into the current zoom
                break;
            }
        }

        return array($markerBox['lat']['center'], $markerBox['lon']['center'], $zoom);
    }

    public function lonToTile($long, $zoom) {
        return (($long + 180) / 360) * pow(2, $zoom);
    }

    public function latToTile($lat, $zoom) {
        return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
    }

    public function initCoords() {
        $this->centerX = $this->lonToTile($this->lon, $this->zoom);
        $this->centerY = $this->latToTile($this->lat, $this->zoom);
        $this->offsetX = floor((floor($this->centerX) - $this->centerX) * $this->tileSize);
        $this->offsetY = floor((floor($this->centerY) - $this->centerY) * $this->tileSize);
    }

    public function createBaseMap() {
        $this->image = imagecreatetruecolor($this->width, $this->height);
        $startX = floor($this->centerX - ($this->width / $this->tileSize) / 2);
        $startY = floor($this->centerY - ($this->height / $this->tileSize) / 2);
        $endX = ceil($this->centerX + ($this->width / $this->tileSize) / 2);
        $endY = ceil($this->centerY + ($this->height / $this->tileSize) / 2);
        $this->offsetX = -floor(($this->centerX - floor($this->centerX)) * $this->tileSize);
        $this->offsetY = -floor(($this->centerY - floor($this->centerY)) * $this->tileSize);
        $this->offsetX += floor($this->width / 2);
        $this->offsetY += floor($this->height / 2);
        $this->offsetX += floor($startX - floor($this->centerX)) * $this->tileSize;
        $this->offsetY += floor($startY - floor($this->centerY)) * $this->tileSize;

        for ($x = $startX; $x <= $endX; $x++) {
            for ($y = $startY; $y <= $endY; $y++) {
                $url = str_replace(array('{Z}', '{X}', '{Y}'), array($this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
                $tileData = $this->fetchTile($url);
                if ($tileData) {
                    $tileImage = imagecreatefromstring($tileData);
                } else {
                    $tileImage = imagecreate($this->tileSize, $this->tileSize);
                    $color = imagecolorallocate($tileImage, 255, 255, 255);
                    @imagestring($tileImage, 1, 127, 127, 'err', $color);
                }
                $destX = ($x - $startX) * $this->tileSize + $this->offsetX;
                $destY = ($y - $startY) * $this->tileSize + $this->offsetY;
                imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
            }
        }
    }

    public function placeMarkers() {
        foreach ($this->markers as $marker) {
            $markerLat = $marker['lat'];
            $markerLon = $marker['lon'];
            $markerType = $marker['type'];

// clear variables from previous loops
            $markerFilename = $markerType;
            $markerShadow = '';
            $matches = false;

            $markerImageOffsetX = 0;
            $markerImageOffsetY = 0;
            $filepath = '../' . $markerFilename;
            if (file_exists($filepath)) {
                $max_h = $max_w = 0;
                list($img_w, $img_h) = getimagesize($filepath);
                $markerImg = imagecreatefrompng($filepath);
                $markerImageOffsetX = $img_w / 2 * (-1);
                $markerImageOffsetY = $img_h * (-1); // We don't want to center the Y because our images have a point at the bottom.
// calculate pixel position from geographical location
                $destX = floor(($this->width / 2) - $this->tileSize * ($this->centerX - $this->lonToTile($markerLon, $this->zoom)));
                $destY = floor(($this->height / 2) - $this->tileSize * ($this->centerY - $this->latToTile($markerLat, $this->zoom)));

// check for shadow + create shadow resource
                if ($markerShadow && file_exists($this->markerBaseDir . '/' . $markerShadow)) {
                    $markerShadowImg = imagecreatefrompng($this->markerBaseDir . '/' . $markerShadow);
                    /** @noinspection PhpUndefinedVariableInspection - if $markerShadow is falsy, so are $markerShadowOffset{X|Y}  */
                    imagecopy($this->image, $markerShadowImg, $destX + intval($markerShadowOffsetX), $destY + intval($markerShadowOffsetY), 0, 0, imagesx($markerShadowImg), imagesy($markerShadowImg));
                }

// copy marker to basemap above shadow
                imagecopy($this->image, $markerImg, $destX + intval($markerImageOffsetX), $destY + intval($markerImageOffsetY), 0, 0, imagesx($markerImg), imagesy($markerImg));
            }
        };
    }

    public function tileUrlToFilename($url) {
        return $this->tileCacheBaseDir . "/" . str_replace(array('http://'), '', $url);
    }

    public function checkTileCache($url) {
        $filename = $this->tileUrlToFilename($url);
        if (file_exists($filename)) {
            return file_get_contents($filename);
        } else {
            return '';
        }
    }

    public function checkMapCache() {
        $filename = $this->mapCacheIDToFilename();
        return (file_exists($filename));
    }

    public function serializeParams() {
        return join("&", array($this->zoom, $this->lat, $this->lon, $this->width, $this->height, serialize($this->markers), $this->maptype, $this->scale, $this->format));
    }

    public function mapCacheIDToFilename() {
        if (!$this->mapCacheFile) {
            $this->mapCacheFile = $this->mapCacheBaseDir . "/" . $this->maptype . "/" . $this->zoom . "/cache_" . substr($this->mapCacheID, 0, 2) . "/" . substr($this->mapCacheID, 2, 2) . "/" . substr($this->mapCacheID, 4);
        }
        return $this->mapCacheFile . "." . $this->mapCacheExtension;
    }

    public function mkdir_recursive($pathname, $mode) {
        is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);
        return is_dir($pathname) || @mkdir($pathname, $mode);
    }

    public function writeTileToCache($url, $data) {
        $filename = $this->tileUrlToFilename($url);
        $this->mkdir_recursive(dirname($filename), 0777);
        file_put_contents($filename, $data);
    }

    public function fetchTile($url) {
        if ($this->useTileCache && ($cached = $this->checkTileCache($url)))
            return $cached;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // time out faster
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // time out faster - but not too fast
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->ua);
        curl_setopt($ch, CURLOPT_URL, $url);
        $tile = curl_exec($ch);
        curl_close($ch);
        if ($tile && $this->useTileCache) { // cache if result
            $this->writeTileToCache($url, $tile);
        }
        return $tile;
    }

    public function checkCurlFunctions() {
        return (function_exists('curl_init') && function_exists('curl_setopt') && function_exists('curl_exec') && function_exists('curl_close'));
    }

    public function checkGdFunctions() {
        return (function_exists('imagecreatefrompng') && function_exists('imagecopy') && function_exists('imagepng'));
    }

    public function copyrightNotice() {
// add OSM logo
        $logoImg = imagecreatefrompng($this->osmLogo);
        imagecopy($this->image, $logoImg, imagesx($this->image) - imagesx($logoImg), imagesy($this->image) - imagesy($logoImg) - 4, 0, 0, imagesx($logoImg), imagesy($logoImg));
        $logoImg = imagecreatefrompng($this->or911Logo);
        imagecopy($this->image, $logoImg, 0, imagesy($this->image) - imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));
    }

    public function sendHeader($fname = null, $etag = null) {
        header('Content-Type: image/' . $this->format); // it's an image
        header("Pragma: public"); // ancient IE hack

        if ($this->useHTTPCache) {
            $expires = (60 * 60 * 24) * $this->expireDays;
            header("Cache-Control: maxage=" . $expires);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
            if ($fname != null && file_exists($fname)) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($fname)) . ' GMT');
            }
            if ($etag != null) {
                header('ETag: ' . $etag);
            }
        }
    }

    public function makeMap() {
        $this->initCoords();
        $this->createBaseMap();
        if ($this->osmLogo)
            $this->copyrightNotice();
        if (count($this->markers))
            $this->placeMarkers();
    }

    public function showMap() {
        $etag = $this->mapCacheID; // which now means that "same ETag" means "same parameters" (a hash collision is unlikely under these circumstances)
// if we have sent an ETag to the browser previously, this is how we get it back
        if ($this->useHTTPCache && array_key_exists('HTTP_IF_NONE_MATCH', $this->requestHeaders)) {
            if ($etag == $this->requestHeaders['HTTP_IF_NONE_MATCH']) {
// No changes, don't send anything - the browser already has it.
                header('HTTP/1.1 304 Not Modified');
                return '';
            }
        }
        $this->parseParams();
        if ($this->useMapCache) {
// use map cache, so check cache for map
            if (!$this->checkMapCache()) {
// map is not in cache, needs to be built..
                $this->makeMap();
// ...and stored to disk, if possible
                $this->mkdir_recursive(dirname($this->mapCacheIDToFilename()), 0777);
                $this->applyOutputFilters($this->image, $this->mapCacheIDToFilename(), 9);
                if (file_exists($this->mapCacheIDToFilename())) {
// we have a file, so we can check for its modification date later; but we also send the ETag
                    $this->sendHeader($this->mapCacheIDToFilename(), $etag);
                    return file_get_contents($this->mapCacheIDToFilename());
                } else {
// map is not stored in disk cache, so we only send the ETag
                    $this->sendHeader(null, $etag);
                    return $this->applyOutputFilters($this->image);
                }
            } else {
// map is in our disk cache
                if ($this->useHTTPCache && array_key_exists('HTTP_IF_MODIFIED_SINCE', $this->requestHeaders)) {
                    $request_time = strtotime($this->requestHeaders['HTTP_IF_MODIFIED_SINCE']);
                    $file_time = filemtime($this->mapCacheIDToFilename());
                    if ($request_time >= $file_time) {
// the map is already in browser's cache, we don't need to send anything
                        header('HTTP/1.1 304 Not Modified');
                        return '';
                    }
                }
// we have a file, so we can check for its modification date later; but we also send the ETag
                $this->sendHeader($this->mapCacheIDToFilename(), $etag);
                return file_get_contents($this->mapCacheIDToFilename());
            }
        } else {
// no cache, make map, send headers and deliver png
            $this->makeMap();
            $this->sendHeader(null, $etag);
            return $this->applyOutputFilters($this->image);
        }
    }

    protected function applyOutputFilters($image_orig, $filename = null, $quality = null, $filters = null) {
// scale if required
        if ($this->scale != 1) {
            $w = $this->scale * $this->width;
            $h = $this->scale * $this->height;
            $image = imagecreatetruecolor($w, $h);
            imagecopyresampled($image, $image_orig, 0, 0, 0, 0, $w, $h, $this->width, $this->height);
        } else {
            $image = $image_orig;
        }
        unset($image_orig);

// apply the required output format
        if ($this->format == 'jpeg') {
// quality is PNG-derived (0-9), convert to something JPEG-worthy
            return imagejpeg($image, $filename, 130 - ($quality * 10));
        } else if ($this->format == 'gif') {
            return imagegif($image, $filename);
        } else {
            return imagepng($image, $filename, $quality, $filters);
        }
    }

}

class staticMapLiteException extends Exception {
// just so that the caller can catch a sane exception type
}
