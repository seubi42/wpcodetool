<?php

use Smbb\Sample\CodeTool\SamplePublicRoutes;

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/classes/SamplePublicRoutes.php';

codetool_route()->get()->on('sample/ping', function () {
    $routes = new SamplePublicRoutes();

    return $routes->ping("get");
});

codetool_route()->post()->on('sample/ping', function () {
    $routes = new SamplePublicRoutes();

    return $routes->previewImage("post");
});


codetool_route()->get()->on('sample/preview/*', function ($id) {
    $routes = new SamplePublicRoutes();

    return $routes->preview($id);
});

codetool_route()->get()->on('sample/preview-images/*.jpg', function ($id) {
    $routes = new SamplePublicRoutes();

    return $routes->previewImage($id);
});
