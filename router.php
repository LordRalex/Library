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

$klein->respond('GET', '/search', function($request, $response, $service, $app) {
    $service->render('search.phtml');
});

$klein->respond('GET', '/login', function($request, $response, $service, $app) {
    $service->render('login.phtml');
});

$klein->respond('POST', '/login', function($request, $response, $service, $app) {
    try {
        $service->validateParam('email', 'Please enter a valid eamail')->isLen(5, 256);
        $service->validateParam('password', 'Please enter a password')->isLen(1, 256);
        $statement = $app->librarydb->prepare("SELECT uuid,password,email FROM user WHERE email=?");
        $statement->execute(array($request->param("email")));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetch();

        if (!isset($db['password']) || !isset($db['uuid']) || !isset($db['email']) || !password_verify($request->param('password'), $db['password'])) {
            $service->flash('The given email and password is incorrect');
            $response->redirect('/login', 302);
            return;
        }
        $_SESSION['email'] = $db['email'];
        $_SESSION['password'] = $db['password'];
        $response->redirect('/', 302);
    } catch (Exception $e) {
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect('/login', 302);
        return;
    }
});

$klein->respond('GET', '/logout', function($request, $response, $service, $app) {
    $_SESSION['password'] = null;
    $_SESSION['email'] = null;
    $response->redirect('/', 302);
});

$klein->respond('POST', '/search', function($request, $response, $service, $app) {
    if ($request->param("type") === null) {
        echo json_encode(array("msg" => "failed", "error" => "No search type provided"));
        return;
    }
    if ($request->param("query") === null) {
        echo json_encode(array("msg" => "failed", "error" => "No search arguments provided"));
        return;
    }
    try {
        $database = $app->librarydb;
        switch ($request->param("type")) {
            case "author":
                $statement = $database->prepare("SELECT title, book.desc, book.isbn FROM book "
                        . "INNER JOIN bookauthor ON bookauthor.isbn = book.isbn "
                        . "WHERE author LIKE ?"
                        . "LIMIT 30");
                $statement->execute(array(0 => "%" . $request->param("query") . "%"));
                break;

            case "isbn":
                $statement = $database->prepare("SELECT title, book.desc, book.isbn FROM book "
                        . "INNER JOIN bookauthor ON bookauthor.isbn = book.isbn "
                        . "WHERE book.isbn = ?"
                        . "LIMIT 30");
                $statement->execute(array(0 => $request->param("query")));
                break;

            case "title":
                $statement = $database->prepare("SELECT title, book.desc, book.isbn FROM book "
                        . "INNER JOIN bookauthor ON bookauthor.isbn = book.isbn "
                        . "WHERE title LIKE ? "
                        . "LIMIT 30");
                $statement->execute(array(0 => "%" . $request->param("query") . "%"));
                break;

            default:
                echo json_encode(array("msg" => "failed", "error" => "Invalid search type"));
                return;
        }        
        $books = $statement->fetchALL(PDO::FETCH_ASSOC);
        echo json_encode(array("msg" => "success", "data" => $books));
    } catch (PDOException $ex) {
        error_log($ex);
        echo json_encode(array("msg" => "failed", "error" => "Database returned an error"));
    }
});

$klein->dispatch();