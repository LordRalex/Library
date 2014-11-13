<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

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

$klein->respond('GET', '/logout', function($request, $response, $service, $app) {
    //TODO: Improve security of logout
    //You can currently force another user to log out by sending their email
    $email = $request->cookies()['email'];
    $response->cookie('session', null);
    $response->cookie('email', null);
    $app->librarydb->prepare("UPDATE user SET session = NULL WHERE email = ?")->execute(array(0 => $email));
    $response->redirect('/', 302);
});

$klein->respond('GET', '/resetpw', function($request, $response, $service, $app) {
    try {
        $request->validateParam('e', 'No email provided')->isEmail();
        $request->validateParam('k', 'No reset key provided');
        $database = $app->librarydb;
        $statement = $database->prepare("SELECT passphrase AS k FROM passwordreset "
              . "INNER JOIN user ON user.uuid = userId "
              . "WHERE user.email = ?");
        $statement->execute(array(0 => $request->param('e')));
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($result['k'] === $request->param('k')) {
            $database->prepare("DELETE FROM passwordreset "
                        . "INNER JOIN user ON user.uuid = userId "
                        . "WHERE user.email = ?")
                  ->execute(array(0 => $request->param('e')));
        } else {
            $response->flash("Reset link not valid");
            $response->redirect('/login', 302);
            return;
        }
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect('/login', 302);
        return;
    }
});

$klein->respond('GET', '/', function($request, $response, $service, $app) {
    $service->render("home.phtml", array('randomBook' => randBook($app)));
});

$klein->respond('GET', '/[a:page]', function ($request, $response, $service, $app) {
    $page = $request->param('page');
    if ($page === null || $page === 'home' || $page === "logout") {
        $service->render("home.phtml", array('randomBook' => randBook($app)));
    } else {
        $service->render($page . ".phtml");
    }
});

$klein->respond('POST', '/login', function($request, $response, $service, $app) {
    try {
        $service->validateParam('email', 'Please enter a valid email');
        $service->validateParam('password', 'Please enter a password');
        $statement = $app->librarydb->prepare("SELECT uuid,password,email FROM user WHERE email=?");
        $statement->execute(array($request->param("email")));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetch();

        if (!isset($db['password']) || !isset($db['uuid']) || !isset($db['email']) || !password_verify($request->param('password'), $db['password'])) {
            $service->flash('The given email and password is incorrect');
            $response->redirect('/login', 302);
            return;
        }
        $session = generate_random_string(32);
        $response->cookie('session', $session);
        $response->cookie('email', $db['email']);
        $app->librarydb->prepare("UPDATE user SET session = ? WHERE email = ?")->execute(array(0 => $session, 1 => $db['email']));
        $response->redirect('/', 302);
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect('/login', 302);
        return;
    }
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
                $statement = $database->prepare("SELECT DISTINCT title, book.desc, isbn, author FROM book "
                      . "WHERE author LIKE ?"
                      . "LIMIT 30");
                $statement->execute(array(0 => "%" . $request->param("query") . "%"));
                break;

            case "isbn":
                $statement = $database->prepare("SELECT DISTINCT title, book.desc, isbn, author FROM book "
                      . "WHERE book.isbn = ?"
                      . "LIMIT 30");
                $statement->execute(array(0 => $request->param("query")));
                break;

            case "title":
                $statement = $database->prepare("SELECT DISTINCT title, book.desc, isbn, author FROM book "
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

$klein->respond('POST', '/resetpassword', function($request, $response, $service, $app) {
    try {
        $key = generate_random_string(32);
        $db = $app->librarydb;
        $statement = $db->prepare("SELECT uuid FROM user WHERE email = ? LIMIT 1");
        $statement->execute(array($request->param('email')));
        $uuid = $statement->fetchALL(PDO::FETCH_ASSOC);
        if (count($uuid) === 1 && isset($uuid[0])) {
            $id = $uuid[0]['uuid'];
            $db->prepare("INSERT INTO passwordreset (userId, passphrase) VALUES (?,?) "
                        . "ON DUPLICATE KEY UPDATE passphrase = ?")
                  ->execute(array($id, $key, $key));
            $mailgun = new \Mailgun\Mailgun(getMailgunKey());
            $mailgun->sendMessage('ae97.net', array(
                'from' => 'library@ae97.net',
                'to' => $request->param('email'),
                'subject' => 'Password Reset',
                'html' => 'Someone recently requested a password reset for your account. '
                . 'If this was your choice, then please click <a href="http://library.ae97.net/resetpw?e='
                . $request->param('email') . '&k=' . $key . '">this link</a> to complete the process'
            ));
        }

        $service->flash('Please check your email for a password reset link');
    } catch (PDOException $ex) {
        $service->flash("A database error occured");
        error_log($ex);
    }
    $response->redirect('/login', 302);
});


$klein->dispatch();

function generate_random_string($length) {
    $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $str = '';
    $count = strlen($charset);
    for ($i = 0; $i < $length; $i++) {
        $str .= $charset[mt_rand(0, $count - 1)];
    }
    return $str;
}

function isLoggedIn() {
    return isset($_COOKIE['session']);
}

function randBook($app) {
    $database = $app->librarydb;
    $statement = $database->prepare("SELECT DISTINCT isbn FROM book");
    $statement->execute();
    $books = $statement->fetchALL(PDO::FETCH_ASSOC);
    $randBook = $database->prepare("SELECT title, author, book.desc FROM book "
          . "WHERE isbn = ?");
    $randBook->execute(array(0 => $books[mt_rand(0, count($books) - 1)]['isbn']));
    return $randBook->fetchALL(PDO::FETCH_ASSOC);
}
