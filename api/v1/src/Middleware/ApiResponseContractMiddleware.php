<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\ApiResponseEnvelope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;

final class ApiResponseContractMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);
        if (!ApiRequestFieldMiddleware::isApiRequestPath($request->getUri()->getPath())) {
            return $response;
        }

        $status = $response->getStatusCode();
        if (
            $status < 200
            || in_array($status, [204, 205], true)
            || ($status >= 300 && $status < 400)
            || strtoupper($request->getMethod()) === 'HEAD'
        ) {
            return $response;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'json')) {
            return $response;
        }

        $body = $response->getBody();
        if (!$body->isSeekable()) {
            error_log('API contract middleware cannot normalize a non-seekable response body.');
            return $response;
        }

        $body->rewind();
        $contents = $body->getContents();
        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            error_log('API handler produced invalid JSON: ' . $exception->getMessage());
            $status = 500;
            $payload = [
                'status' => 'error',
                'message' => 'تعذر إنشاء استجابة API صالحة.',
                'errors' => null,
            ];
        }

        try {
            $normalized = ApiResponseEnvelope::normalize($payload, $status);
        } catch (\InvalidArgumentException $exception) {
            error_log('API response contains ambiguous field names: ' . $exception->getMessage());
            $status = 500;
            $normalized = ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'تعذر إنشاء استجابة API صالحة.',
                'errors' => ['code' => 'AMBIGUOUS_FIELDS'],
            ], $status);
        }
        $serialized = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $response
            ->withBody((new StreamFactory())->createStream($serialized))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Length', (string) strlen($serialized))
            ->withStatus($status);
    }
}
