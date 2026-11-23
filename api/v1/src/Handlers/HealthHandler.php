<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthHandler
{
    public function check(Request $request, Response $response): Response
    {
        $data = [
            'status' => 'ok',
            'service' => 'SmartSys API',
            'version' => '1.0.0',
            'timestamp' => time(),
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
