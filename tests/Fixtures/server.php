<?php

declare(strict_types=1);

// Router for `php -S`, echoes what it received so tests can check what went over the wire.

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/redirect':
        header('Location: /echo', true, 302);
        exit;

    case '/slow':
        sleep(2);
        echo 'late';
        exit;

    case '/gzip':
        header('Content-Encoding: gzip');
        echo gzencode('compressed body');
        exit;

    case '/status/500':
        http_response_code(500);
        echo 'boom';
        exit;
}

header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
]);
