<?php

$klein->respond('*', function($request, $response, $service, $app) use ($klein) {
    if (isLoggedIn() !== 'admin') {
        $response->redirect('/login', 302)->send();
        $klein->skipRemaining();
    }
});

$klein->respond('GET', '/', function($request, $response, $service, $app) {
    $service->render('admin.phtml');
    $response->send();
});

$klein->respond('GET', '/checkout', function($request, $response, $service, $app) {
    $service->render('checkout.phtml');
    $response->send();
});

$klein->respond('GET', '/checkin', function($request, $response, $service, $app) {
    $service->render('checkin.phtml');
    $response->send();
});

$klein->respond('GET', '/addbook', function($request, $response, $service, $app) {
    $service->render('addbook.phtml');
    $response->send();
});

$klein->respond('POST', '/addbook', function($request, $response, $service, $app) {
    try {
        $service->validateParam('isbn', 'No ISBN provided')->notNull();
        $db = $app->librarydb;
        $isbn = $request->param('isbn');
        if ($request->param('new', false)) {
            $service->validateParam('title', 'No book title specified')->notNull();
            $service->validateParam('desc', 'No book description provided')->notNull();
            $service->validateParam('author', 'No author specified')->notNull();
            $service->validateParam('genres', 'No genre specified')->notNull();
            $db->prepare("INSERT INTO book VALUES (?, ?, ?, ?)")
                    ->execute(array($isbn, $request->param('title'), $request->param('desc'), $request->param('author')));
            $genres = explode(',', $request->param('genres'));
            foreach ($genres as $genre) {
                $db->prepare("INSERT INTO bookgenre VALUES (?, ?)")
                        ->execute(array($isbn, $genre));
            }
            $db->prepare("INSERT INTO bookuuid (uuid, isbn) VALUES (?, ?)")
                    ->execute(array(getGUID(), $isbn));
        } else {
            $db->prepare("INSERT INTO bookuuid (uuid, isbn) VALUES (?, ?)")
                    ->execute(array(getGUID(), $isbn));
        }
    } catch (Exception $ex) {
        $service->flash($ex->getMessage());
    }
    $service->refresh();
});

$klein->respond('GET', '/bookstatus', function($request, $response, $service, $app) {
    if ($request->param('isbn') === null) {
        echo json_encode(array(
            'result' => 'failed',
            'cause' => 'No ISBN in request'
        ));
        return;
    }
    try {
        $db = $app->librarydb;
        if ($request->param('status') === 'in') {
            $statement = $db->prepare("SELECT uuid FROM bookuuid WHERE isbn = ? AND checkedout = 0");
        } else if ($request->param('status') === 'out') {
            $statement = $db->prepare("SELECT uuid FROM bookuuid WHERE isbn = ? AND checkedout = 1");
        } else {
            $statement = $db->prepare("SELECT uuid, checkedout FROM bookuuid WHERE isbn = ?");
        }
        $statement->execute(array($request->param('isbn')));
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array(
            'result' => 'success',
            'data' => $results
        ));
    } catch (PDOException $ex) {
        echo json_encode(array(
            'result' => 'failed',
            'cause' => $ex->getMessage()
        ));
        return;
    }
});
