<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\ApiFieldNameMapper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiRequestFieldMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (!self::isApiRequestPath($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $request = $request->withQueryParams(
            ApiFieldNameMapper::toSnakeCase($request->getQueryParams())
        );

        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) || is_object($parsedBody)) {
            $request = $request->withParsedBody(ApiFieldNameMapper::toSnakeCase($parsedBody));
        }

        return $handler->handle($request);
    }

    public static function isApiRequestPath(string $path): bool
    {
        return preg_match('#(?:^|/)api/v1(?:/|$)#', $path) === 1;
    }
}
