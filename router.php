<?php

require_once __DIR__ . '/assets/php/composer/vendor/autoload.php';

session_start();

$klein = new \Klein\Klein();

$klein->respond(function($request, $response, $service, $app) {
    $app->register('librarydb', function() {
        $_DATABASE = array(
            'host' => 'localhost',
            'db' => 'library',
            'user' => 'library',
            'pass' => 'library'
        );
        $db = new PDO("mysql:host=" . $_DATABASE['host'] . ";dbname=" . $_DATABASE['db'], $_DATABASE['user'], $_DATABASE['pass'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    });
});


$klein->respond('GET', '/?[index|home:page]?', function($request, $response, $service, $app) {
    $service->render('home.phtml');
});

$klein->respond('GET', '/login', function($request, $response, $service, $app) {
    $service->render('login.phtml');
});

$klein->respond('POST', '/login', function($request, $response, $service, $app) {
    try {
        $service->validateParam('email', 'Please enter a valid eamail')->isLen(5, 256);
        $service->validateParam('password', 'Please enter a password')->isLen(1, 256);
        $statement = $app->librarydb->prepare("SELECT uuid,password FROM user WHERE email=?");
        $statement->execute(array($request->param("email")));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetch();

        if (!isset($db['password']) || !isset($db['uuid']) || !password_verify($request->param('password'), $db['password'])) {
            $service->flash('The given email and password is incorrect');
            $response->redirect('/login', 302);
            return;
        }
        $response->redirect('/', 302);
    } catch (Exception $e) {
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect('/login', 302);
        return;
    }
});

$klein->dispatch();
