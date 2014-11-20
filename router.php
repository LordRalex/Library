<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

session_start();

$klein = new \Klein\Klein();

$klein->respond(function($request, $response, $service, $app) {
    $app->register('librarydb', function() {
        $_DATABASE = getDatabaseConfig();
        $db = new PDO("mysql:host=" . $_DATABASE['host'] . ";dbname=" . $_DATABASE['db'], $_DATABASE['user'], $_DATABASE['pass'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    });
});

$klein->respond('GET', '/logout', function($request, $response, $service, $app) {
    $uuid = $request->cookies()['uuid'];
    $response->cookie('session', null);
    $response->cookie('email', null);
    $app->librarydb->prepare("UPDATE user SET session = NULL WHERE uuid = ?")->execute(array($uuid));
    $response->redirect('/', 302)->send();
});

$klein->respond('GET', '/resetpw', function($request, $response, $service, $app) {
    try {
        $service->validateParam('e', 'No email provided')->isEmail();
        $service->validateParam('k', 'No reset key provided');
        $database = $app->librarydb;
        $statement = $database->prepare("SELECT passphrase AS k FROM passwordreset "
                . "INNER JOIN user ON user.uuid = userId "
                . "WHERE user.email = ?");
        $statement->execute(array(0 => $request->param('e')));
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($result[0]['k'] === $request->param('k')) {
            $database->prepare("DELETE FROM passwordreset "
                            . "WHERE userId = (SELECT uuid FROM user WHERE user.email = ?)")
                    ->execute(array(0 => $request->param('e')));
            $newpw = generate_random_string(8);
            $database->prepare('UPDATE user SET password = ? WHERE email = ?')
                    ->execute(array(0 => password_hash($newpw, PASSWORD_BCRYPT), 1 => $request->param('e')));
            $mailgun = new \Mailgun\Mailgun(getMailgunKey());
            $mailgun->sendMessage('ae97.net', array(
                'from' => 'library@ae97.net',
                'to' => $request->param('e'),
                'subject' => 'Password changed',
                'html' => 'Your password has been changed<br>Your new password is: ' . $newpw
            ));
            $service->flash('Please check your email for your new password');
            $response->redirect('/login', 302);
        } else {
            $service->flash("Reset link not valid");
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

$klein->respond('GET', '/validate', function ($request, $response, $service, $app) {
    try {
        $service->validateParam('u', "UUID is not valid")->notNull();
        $service->validateParam('k', "Key is not valid")->notNull()->isLen(36);
    } catch (Exception $ex) {
        $service->flash($ex->getMessage());
        $response->redirect('/login', 302)->send();
        return;
    }
    try {
        $db = $app->librarydb;
        $statement = $db->prepare("SELECT useruuid AS uuid, validation AS k FROM uservalidate WHERE useruuid = ?");
        $statement->execute(array($request->param('u')));
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (isset($result) && count($result) == 1 && $result[0]['k'] === $request->param('k')) {
            $db->prepare("UPDATE user SET verified = 1 WHERE uuid = ?")
                    ->execute(array($result['uuid']));
            $db->prepare("DELETE FROM uservalidate WHERE useruuid = ?")
                    ->execute(array($result['uuid']));
            $service->flash("Your email has been verified, you may now log in");
        } else {
            $service->flash("Invalid user id and key");
        }
    } catch (PDOException $ex) {
        $service->flash("A database error occurred");
        error_log($ex);
    }
    $response->redirect('/login', 302)->send();
});

$klein->respond('GET', '/bookview', function($request, $response, $service, $app) {
    if ($request->param('isbn') == null) {
        $response->redirect('/search', 302)->send();
        return;
    }
    $isbn = $request->param('isbn');
    try {
        $statement = $app->librarydb->prepare("SELECT title, book.desc, book.isbn, author FROM book "
                //. "INNER JOIN bookgenre ON bookgenre.isbn = book.isbn "
                . "WHERE isbn = ? ");
        $statement->execute(array($isbn));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetchAll();

        if (count($db) != 1) {
            $response->redirect('/search', 302)->send();
            return;
        }
        $book = $db[0];
        $service->render("bookview.phtml", array('randomBook' => $book));
        $response->send();
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $response->redirect('/search', 302)->send();
        return;
    }
});

$klein->respond('GET', '/watchlist', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        $response->redirect('/login', 302)->send();
        return;
    }
    try {
        $statement = $app->librarydb->prepare("SELECT book.title, book.desc, book.isbn, book.author FROM book "
                . "INNER JOIN watchlist ON watchlist.isbn=book.isbn "
                . "WHERE watchlist.useruuid = ? ");
        $statement->execute(array($request->cookies()['uuid']));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetchAll();
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $db = array();
    }
    $service->render("watchlist.phtml", array('books' => $db));
    $response->send();
});

$klein->respond('GET', '/', function($request, $response, $service, $app) {
    $service->render("home.phtml", array('randomBook' => randBook($app)));
});

$klein->respond('GET', '/[a:page]', function ($request, $response, $service, $app) {
    if ($response->isSent()) {
        return;
    }
    $page = $request->param('page');
    if ($page === null || $page === 'home') {
        $service->render("home.phtml", array('randomBook' => randBook($app)));
    } else if (file_exists($page . ".phtml")) {
        $service->render($page . ".phtml");
    }
});

$klein->respond('POST', '/login', function($request, $response, $service, $app) {
    try {
        $service->validateParam('email', 'Please enter a valid email');
        $service->validateParam('password', 'Please enter a password');
        $statement = $app->librarydb->prepare("SELECT uuid, password FROM user WHERE email = ?");
        $statement->execute(array($request->param("email")));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetch();

        if (!isset($db['password']) || !isset($db['uuid']) || !password_verify($request->param('password'), $db['password'])) {
            $service->flash('The given email and password is incorrect');
            $response->redirect('/login', 302);
            return;
        }
        $session = generate_random_string(32);
        $response->cookie('session', $session);
        $response->cookie('uuid', $db['uuid']);
        $app->librarydb->prepare("UPDATE user SET session = ? WHERE uuid = ?")->execute(array(0 => $session, 1 => $db['uuid']));
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

            case "genre":
                $statement = $database->prepare("SELECT DISTINCT title, book.desc, book.isbn, author FROM book "
                        . "INNER JOIN bookgenre ON bookgenre.isbn = book.isbn "
                        . "WHERE genre = ? "
                        . "LIMIT 30");
                $statement->execute(array(0 => $request->param("query")));
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
            $db->prepare("INSERT INTO passwordreset (userId, passphrase) VALUES (?, ?) "
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

$klein->respond('POST', '/register', function ($request, $response, $service, $app) {
    $service->addValidator('equal', function ($str, $param) use ($request) {
        return $str === $request->param($param);
    });

    try {
        $service->validateParam('email', 'Invalid email provided')->isEmail()->notNull();
        $service->validateParam('email', 'Emails do not match')->isEqual('retypeemail');
        $service->validateParam('name', 'You must provide a name')->notNull();
        $service->validateParam('password', 'Password must be between 4 and 64 characters')->notNull()->isLen(4, 64);
        $service->validateParam('password', 'Passwords do not match')->isEqual('retypepassword');
    } catch (Exception $ex) {
        $service->flash($ex->getMessage());
        $response->redirect('/register', 302);
        return;
    }
    try {
        $db = $app->librarydb;
        $phone = $request->param('phone');
        if ($phone == null || $phone->trim() === '') {
            $phone = null;
        }
        $uuid = getGUID();
        $db->prepare("INSERT INTO user (uuid, name, password, email, phone) VALUES (?, ?, ?, ?, ?)")
                ->execute(array(
                    $uuid,
                    $request->param('name'),
                    password_hash($request->param('password'), PASSWORD_BCRYPT),
                    $request->param('email'),
                    $phone
        ));
        $validationKey = generate_random_string(36);
        $db->prepare("INSERT INTO uservalidate (useruuid, validation) VALUES (?, ?)")
                ->execute(array($uuid, $validationKey));

        $service->flash('Your account has been created, check your email for the verification');
        $mailgun = new \Mailgun\Mailgun(getMailgunKey());
        $mailgun->sendMessage('ae97.net', array(
            'from' => 'library@ae97.net',
            'to' => $request->param('email'),
            'subject' => 'Account validation',
            'html' => 'Someone recently created an account for this email on http://library.ae97.net. '
            . 'If this was your choice, then please click <a href="http://library.ae97.net/validate?u='
            . $uuid . '&k=' . $validationKey . '">this link</a> to complete the process'
        ));
        $response->redirect('/login', 302);
    } catch (PDOException $ex) {
        $service->flash('An error occured while creating your account');
        $service->flash($ex->getMessage());
        $response->redirect('/register', 302);
    }
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
    if (!isset($_COOKIE['session']) || !isset($_COOKIE['uuid'])) {
        return false;
    }
    $session = $_COOKIE['session'];
    $uuid = $_COOKIE['uuid'];
    try {
        $_DATABASE = getDatabaseConfig();
        $db = new PDO("mysql:host=" . $_DATABASE['host'] . ";dbname=" . $_DATABASE['db'], $_DATABASE['user'], $_DATABASE['pass'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"));
        $statement = $db->prepare("SELECT uuid, session, rights FROM user WHERE uuid = ?");
        $statement->execute(array($uuid));
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (isset($result) && count($result) == 1) {
            return $result[0]['rights'];
        } else {
            return false;
        }
    } catch (PDOException $ex) {
        error_log($ex);
        return false;
    }
}

function randBook($app) {
    $database = $app->librarydb;
    $statement = $database->prepare("SELECT DISTINCT isbn FROM book");
    $statement->execute();
    $books = $statement->fetchALL(PDO::FETCH_ASSOC);
    $randBook = $database->prepare("SELECT title, author, book.desc FROM book "
            . "WHERE isbn = ? LIMIT 1");
    $randBook->execute(array(0 => $books[mt_rand(0, count($books) - 1)]['isbn']));
    return $randBook->fetchALL(PDO::FETCH_ASSOC)[0];
}

function getGUID() {
    if (function_exists('com_create_guid')) {
        return com_create_guid();
    } else {
        mt_srand((double) microtime() * 10000); //optional for php 4.2.0 and up.
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45); // "-"
        $uuid = substr($charid, 0, 8) . $hyphen
                . substr($charid, 8, 4) . $hyphen
                . substr($charid, 12, 4) . $hyphen
                . substr($charid, 16, 4) . $hyphen
                . substr($charid, 20, 12);
        return $uuid;
    }
}
