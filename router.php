<?php

require_once __DIR__ . '/assets/php/composer/vendor/autoload.php';

session_start();

$klein = new \Klein\Klein();

$klein->respond('GET', '/?[index|home:page]?', function($request, $response, $service, $app) {
    $service->render('home.phtml');
});

$klein->dispatch();
