<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\ApiRequestFieldMiddleware;
use App\Middleware\ApiResponseContractMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ApiContractMiddlewareTest extends TestCase
{
    public function testCamelCaseRequestBodyAndQueryAreMappedToInternalNames(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/smartsys/api/v1/items')
            ->withQueryParams(['branchId' => 7])
            ->withParsedBody([
                'productId' => 3,
                'lineItems' => [['unitPrice' => 10]],
                'settings' => ['company.currency_code' => 'EGP'],
            ]);
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $receivedRequest = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->receivedRequest = $request;
                return new Response();
            }
        };

        (new ApiRequestFieldMiddleware())->process($request, $handler);

        self::assertSame(['branch_id' => 7], $handler->receivedRequest?->getQueryParams());
        self::assertSame([
            'product_id' => 3,
            'line_items' => [['unit_price' => 10]],
            'settings' => ['company.currency_code' => 'EGP'],
        ], $handler->receivedRequest?->getParsedBody());
    }

    public function testJsonResponseIsWrappedAndCamelCased(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/items');
        $handler = self::jsonHandler(
            200,
            ['status' => 'success', 'message' => 'Loaded', 'data' => ['branch_id' => 7, 'item_count' => 2]]
        );

        $response = (new ApiResponseContractMiddleware())->process($request, $handler);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame([
            'status' => 'success',
            'message' => 'Loaded',
            'data' => ['branchId' => 7, 'itemCount' => 2],
            'errors' => null,
            'meta' => [],
        ], $payload);
    }

    public function testErrorsUseTheSameEnvelopeAndCamelCaseDetails(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/items');
        $handler = self::jsonHandler(422, [
            'status' => 'error',
            'message' => 'Invalid item',
            'error_code' => 'INVALID_ITEM',
            'details' => ['product_id' => 'Required'],
        ]);

        $response = (new ApiResponseContractMiddleware())->process($request, $handler);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'status' => 'error',
            'message' => 'Invalid item',
            'data' => null,
            'errors' => ['errorCode' => 'INVALID_ITEM', 'details' => ['productId' => 'Required']],
            'meta' => [],
        ], $payload);
    }

    public function testNonApiJsonAndApiDownloadsAreLeftUntouched(): void
    {
        $middleware = new ApiResponseContractMiddleware();
        $nonApiRequest = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        $jsonHandler = self::jsonHandler(200, ['status' => 'ok']);
        $nonApiResponse = $middleware->process($nonApiRequest, $jsonHandler);

        self::assertSame('{"status":"ok"}', (string) $nonApiResponse->getBody());
        self::assertSame(200, $nonApiResponse->getStatusCode());

        $apiRequest = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reports/export');
        $downloadHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Response())->withHeader('Content-Type', 'text/csv');
                $response->getBody()->write("id,name\n1,Item\n");
                return $response;
            }
        };
        $downloadResponse = $middleware->process($apiRequest, $downloadHandler);

        self::assertSame("id,name\n1,Item\n", (string) $downloadResponse->getBody());
        self::assertSame('text/csv', $downloadResponse->getHeaderLine('Content-Type'));
    }

    public function testApiRedirectsAreNotWrapped(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/redirect');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(302))->withHeader('Location', '/destination');
            }
        };

        $response = (new ApiResponseContractMiddleware())->process($request, $handler);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/destination', $response->getHeaderLine('Location'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testNoContentAndHeadResponsesAreNotConvertedToJson(): void
    {
        $middleware = new ApiResponseContractMiddleware();
        $noContentRequest = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/v1/items/1');
        $noContentResponse = (new Response(204))->withHeader('Content-Type', 'application/json');
        $noContent = $middleware->process(
            $noContentRequest,
            new class($noContentResponse) implements RequestHandlerInterface {
                public function __construct(private ResponseInterface $response)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->response;
                }
            }
        );

        self::assertSame(204, $noContent->getStatusCode());
        self::assertSame('', (string) $noContent->getBody());

        $headRequest = (new ServerRequestFactory())->createServerRequest('HEAD', '/api/v1/items');
        $headResponse = self::jsonHandler(200, ['status' => 'success', 'data' => ['id' => 1]]);
        $head = $middleware->process($headRequest, $headResponse);

        self::assertSame('{"status":"success","data":{"id":1}}', (string) $head->getBody());
    }

    public function testAmbiguousApiResponseFieldsReturnAnExplicitServerError(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/items');
        $handler = self::jsonHandler(200, [
            'status' => 'success',
            'data' => ['branch_id' => 7, 'branchId' => 8],
        ]);

        $response = (new ApiResponseContractMiddleware())->process($request, $handler);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('error', $payload['status']);
        self::assertSame('AMBIGUOUS_FIELDS', $payload['errors']['code']);
    }

    private static function jsonHandler(int $status, array $payload): RequestHandlerInterface
    {
        return new class($status, $payload) implements RequestHandlerInterface {
            public function __construct(private int $status, private array $payload)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new Response($this->status))->withHeader('Content-Type', 'application/json');
                $response->getBody()->write((string) json_encode($this->payload));
                return $response;
            }
        };
    }
}
