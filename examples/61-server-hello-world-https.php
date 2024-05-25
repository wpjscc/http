<?php

require __DIR__ . '/../vendor/autoload.php';

$http = new React\Http\HttpServer(function (Psr\Http\Message\ServerRequestInterface $request) {
    return React\Http\Message\Response::plaintext(
        "Hello World!\n"
    );
});

$uri = 'tls://' . ($argv[1] ?? '0.0.0.0:0');
$socket = new React\Socket\SocketServer($uri, [
    'tls' => [
        'local_cert' => $argv[2] ?? __DIR__ . '/localhost.pem'
    ]
]);
$http->listen($socket);

$socket->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

echo 'Listening on ' . str_replace('tls:', 'https:', $socket->getAddress()) . PHP_EOL;
