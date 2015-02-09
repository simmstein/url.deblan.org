<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Silex\Application;
use Symfony\Component\HttpFoundation\RedirectResponse;

$app = new Application();
$app['database_path'] = 'data/url.sqlite.db';

function createDatabase()
{
    global $app;

    $dirname = dirname($app['database_path']);
    $directories = explode('/', $dirname);

    if (!empty($directories)) {
        for ($i = count($directories) - 1; $i > -1; $i--) {
            if (!is_dir($directories[$i])) {
                mkdir($directories[$i]);
            }
        }
    }

    $pdo = getPdo($app);

    $pdo->query('CREATE DATABASE');
    $pdo->query('CREATE TABLE url (
      id varchar(20),
      title varchar(255),
      url varchar(1024),
      redirect_type INTEGER,
      redirect_time INTEGER,
      view,
      password varchar(50),
      date INTEGER,
      ip varchar(255)
    )');
}

function databaseExists()
{
    global $app;

    return file_exists($app['database_path']);
}

function getPdo()
{
    global $app;

    return new \PDO('sqlite:'.$app['database_path']);
}

function getValue($currentValue, $authorized, $default)
{
    if (!in_array($currentValue, $authorized)) {
        return $default;
    }

    return $currentValue;
}

function registerUrl($id, $url, $title, $redirectType, $redirectTime)
{
    $pdo = getPdo();

    $query = $pdo->prepare('insert into url(id, title, url, redirect_type, redirect_time) values(:id, :title, :url, :redirect_type, :redirect_time)');

    $query->execute(array(
        ':id' => $id,
        ':url' => $url,
        ':title' => $title,
        ':redirect_type' => $redirectType,
        ':redirectTime' => $redirectTime,
    ));
}

function arrayToXml(array $d)
{
    $xml = '';

    foreach ($d as $k => $v) {
        if (is_array($v)) {
            $xml.= '<'.$k.'>'.arrayToXml($v).'</'.$k.'>';
        } else {
            $xml.= '<'.$k.'>'.$v.'</'.$k.'>';
        }
    }

    return $xml;
}

function getUrl($id)
{
    $pdo = getPdo();

    $query = $pdo->prepare('select * from url where id=:id');

    $query->execute(array(
        ':id' => $id,
    ));

    return $query->fetch();
}

if (!databaseExists()) {
    createDatabase();
}

$app->error(function (Exception $e, $code) use ($app) {
    $className = get_class($e);
    $message = $e->getMessage();

    return <<<EOF
    <p><strong>ERROR $code</strong></p>
    <p>Exception: $className</p>
    <p>$message</p>
EOF;

});

$app->get('/{id}/', function ($id) use ($app) {
    $url = getUrl($id);

    if (!empty($url)) {
        return new RedirectResponse($url['url']);
    }

    return new RedirectResponse('/');
});

$app->get('/api', function (Request $request) use ($app) {
    $url    = trim($request->query->get('url'));
    $title  = trim($request->query->get('title'));
    $format = getValue(trim($request->query->get('format')), ['xml', 'json', 'text'], 'json');
    $redirectType = getValue(trim($request->query->get('type')), ['http', 'meta'], 'http');
    $redirectTime = getValue((int) trim($request->query->get('time')), [3, 5, 10], 5);

    $data = array(
        'error' => 0,
    );

    if (empty($url)) {
        $data['error'] = 1;
    } else {
        if (!preg_match('#^https?://#', $url)) {
            $data['error'] = 1;
        } else {
            if (!@file_get_contents($url)) {
                $data['error'] = 1;
            }
        }
    }

    if ($data['error'] === 1) {
        $data['message'] = 'Invalid URL.';
    } else {
        $title = preg_replace('/[^a-zA-Z0-9]+/', '', $title);
        $id = dechex(time());

        registerUrl($id, $url, $title, $redirectType, $redirectTime);

        $finalUrl = $request->getSchemeAndHttpHost().'/'.$id.'/';

        $data['url'] = $finalUrl;
    }

    if ($format === 'xml') {
        return arrayToXml($data);
    }

    if ($format === 'json') {
        return json_encode($data);
    }

    if ($format === 'text') {
        if (empty($data['error'])) {
            return $data['url'];
        }
    }
});

$app->run();
