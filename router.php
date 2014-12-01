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
        }
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $service->flash('Error: ' . $e->getMessage());
        $response->redirect('/login', 302);
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
        $statement = $app->librarydb->prepare("SELECT title, book.desc, book.isbn, author, genre FROM book "
                . "INNER JOIN bookgenre ON bookgenre.isbn = book.isbn "
                . "WHERE book.isbn = ? ");
        $statement->execute(array($isbn));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetchAll();

        if (count($db) == 0) {
            $response->redirect('/search', 302)->send();
            return;
        }
        $row = $db[0];
        $output = array(
            'title' => $row['title'],
            'desc' => $row['desc'],
            'author' => $row['author'],
            'isbn' => $row['isbn'],
            'genres' => array()
        );
        foreach ($db as $bookdesc) {
            $output['genres'][] = $bookdesc['genre'];
        }
        if (isLoggedIn()) {
            $findInWatchStmt = $app->librarydb->prepare("SELECT count(*) AS inWatch FROM watchlist "
                    . "WHERE useruuid = ? AND isbn = ?");
            $findInWatchStmt->execute(array($request->cookies()['uuid'], $isbn));
            $findInWatchStmt->setFetchMode(PDO::FETCH_ASSOC);
            $inWatch = $findInWatchStmt->fetch()['inWatch'] >= 1 ? true : false;
        } else {
            $inWatch = false;
        }

        //$inStockStmt = $app->librarydb->prepare('SELECT count(*) AS avail FROM bookuuid WHERE isbn = ? AND checkedout = 0');
        //$inStockStmt->execute(array($isbn));
        //$inStock = $inStockStmt->fetch()[0];
        $inStock = 0;

        $service->render("bookview.phtml", array('book' => $output, 'inWatch' => $inWatch, 'inStock' => $inStock >= 1 ? 'Yes' : 'No'));
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

$klein->respond('GET', '/checkedout', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        $response->redirect('/login', 302)->send();
        return;
    }
    try {
        $statement = $app->librarydb->prepare("SELECT book.title, book.isbn, checkout.returndate AS duedate, book.author FROM book "
                . "INNER JOIN bookuuid ON bookuuid.isbn = book.isbn "
                . "INNER JOIN checkout ON bookuuid.uuid = checkout.bookuuid "
                . "WHERE checkout.useruuid = ? AND returned IS NULL");
        $statement->execute(array($request->cookies()['uuid']));
        $statement->setFetchMode(PDO::FETCH_ASSOC);
        $db = $statement->fetchAll();
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
        $db = array();
    }
    $service->render("checkedout.phtml", array('books' => $db));
    $response->send();
});

$klein->respond('GET', '/watchlist-delete', function($request, $response, $service, $app) {
    if ($request->param('isbn') == null) {
        $response->redirect('/watchlist', 302)->send();
        return;
    }
    $isbn = $request->param('isbn');
    try {
        $statement = $app->librarydb->prepare("DELETE FROM watchlist "
                . "WHERE useruuid = ? AND isbn = ? ");
        $statement->execute(array($request->cookies()['uuid'], $isbn));
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
    }
    $response->redirect('/watchlist', 302)->send();
});

$klein->respond('GET', '/watchlist-add', function($request, $response, $service, $app) {
    if ($request->param('isbn') == null) {
        $response->redirect('/watchlist', 302)->send();
        return;
    }
    $isbn = $request->param('isbn');
    try {
        $statement = $app->librarydb->prepare("INSERT INTO watchlist "
                . "VALUES(?,?)");
        $statement->execute(array($request->cookies()['uuid'], $isbn));
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
    }
    $response->redirect('/watchlist', 302)->send();
});

$klein->respond('GET', '/payment', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        $response->redirect('/login', 302)->send();
        return;
    }
    try {
        $historyStatement = $app->librarydb->prepare("SELECT id, payment, date, description "
                . "FROM transactions "
                . "WHERE user = ?");
        $historyStatement->execute(array($request->cookies()['uuid']));
        $historyStatement->setFetchMode(PDO::FETCH_ASSOC);
        $history = $historyStatement->fetchAll();

        $totalStatement = $app->librarydb->prepare("SELECT SUM(payment) AS total "
                . "FROM transactions "
                . "WHERE user = ?");
        $totalStatement->execute(array($request->cookies()['uuid']));
        $totalStatement->setFetchMode(PDO::FETCH_ASSOC);
        $totalArray = $totalStatement->fetch();
        $total = $totalArray['total'];
        if ($total == '')  {
            $total = 0;
        }
    } catch (Exception $e) {
        if ($e instanceof PDOException) {
            error_log($e);
        }
    }
    $service->render("payment.phtml", array('total' => $total, 'history' => $history));
    $response->send();
});

$klein->respond('POST', '/pay', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        echo false;
        return;
    }
    if ($request->param('ccnumber')==null){
        echo false;
        return;
    }
    try {
        $app->librarydb->prepare('INSERT INTO transactions (user, payment, description) VALUES (?,?,?)')
                ->execute(array($_COOKIE['uuid'],$request->param('total')*-1,'Payment: '.$request->param('ccnumber')));
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        echo false; 
        return;
    }
    echo true;
}); 

$klein->respond('/admin', function($request, $response) {
    $response->redirect('/admin/', 302)->send();
});

$klein->with('/admin', function() use ($klein) {
    include('admin.php');
});

$klein->respond('GET', '/settings', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        $response->redirect('/login', 302)->send();
        return;
    }
    try {
        $db = $app->librarydb;
        $statement = $db->prepare("SELECT name, email, phone FROM user WHERE uuid = ?");
        $statement->execute(array($_COOKIE['uuid']));
        $data = $statement->fetch();
        $service->render("settings.phtml", array('user' => $data));
        $response->send();
    } catch (PDOException $ex) {
        error_log($ex);
        $response->redirect("/home", 302)->send();
    }
});

$klein->respond('GET', '/', function($request, $response, $service, $app) {
    $service->render("home.phtml", array('randomBook' => randBook($app)));
    $response->send();
});

$klein->respond('GET', '/pay', function($request, $response, $service) {
    $service->render("pay.phtml", array('total' => $request->param('total')));
    $response->send();
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
    $response->send();
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

$klein->respond('POST', '/change', function($request, $response, $service, $app) {
    if (!isLoggedIn()) {
        $service->flash('Not logged in');
        $service->back();
        return;
    }
    if ($request->param('change') == null) {
        $service->flash('Invalid change usage');
        $service->back();
        return;
    }
    try {
        $db = $app->librarydb;
        $uuid = $_COOKIE['uuid'];
        switch ($request->param('change')) {
            case 'email':
                $service->validateParam('newemail', "Invalid email provided")->notNull()->isEmail();
                $email = $request->param('newemail');
                if ($email !== $request->param('retypenewemail')) {
                    $service->flash('Emails do not match');
                    break;
                }
                $db->prepare('UPDATE user SET email = ? WHERE uuid = ?')
                        ->execute(array($email, $uuid));
                $service->flash('Email changed');
                break;
            case 'password':
                $password = $request->param('newpassword');
                if ($password !== $request->param('retypenewpassword')) {
                    $service->flash('Passwords do not match');
                    break;
                }
                $db->prepare('UPDATE user SET password = ? WHERE uuid = ?')
                        ->execute(array(password_hash($password, PASSWORD_BCRYPT), $uuid));
                $service->flash('Password changed');
                break;
            case 'phone':
                $phone = $request->param('newphone');
                if ($phone == '') {
                    $phone = null;
                }
                $db->prepare('UPDATE user SET phone = ? WHERE uuid = ?')
                        ->execute(array($phone, $uuid));
                $service->flash('Phone changed');
                break;
            case 'name':
                $name = $request->param('newname');
                if ($name == '') {
                    $name = null;
                }
                $db->prepare('UPDATE user SET name = ? WHERE uuid = ?')
                        ->execute(array($name, $uuid));
                $service->flash('Name changed');
                break;
            default:
                $service->flash('Invalid change usage');
                break;
        }
    } catch (PDOException $ex) {
        error_log($ex);
        $service->flash('Error on changing details');
    } catch (Exception $ex) {
        error_log($ex);
        $service->flash($ex->getMessage());        
    }
    $service->back();
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
        if (isset($result) && count($result) == 1 && $result[0]['session'] === $session) {
            return $result[0]['rights'];
        } else {
            $_COOKIE['session'] = null;
            $_COOKIE['uuid'] = null;
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
    $randBook = $database->prepare("SELECT title, author, book.desc, isbn FROM book "
            . "WHERE isbn = ? LIMIT 1");
    $randBook->execute(array(0 => $books[mt_rand(0, count($books) - 1)]['isbn']));
    return $randBook->fetchALL(PDO::FETCH_ASSOC)[0];
}

function getGUID() {
    if (function_exists('com_create_guid')) {
        return com_create_guid();
    } else {
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
