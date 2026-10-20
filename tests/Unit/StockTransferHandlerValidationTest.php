<?php

declare(strict_types=1);

use App\Handlers\StockTransferHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class StockTransferHandlerValidationTest extends TestCase
{
    public function testTransferRejectsNonPositiveQuantityBeforeStartingTransaction(): void
    {
        foreach ([-2, 0, INF, NAN] as $quantity) {
            $db = new PDO('sqlite::memory:');
            $handler = new StockTransferHandler($db);
            $request = $this->transferRequest([
                'from_branch' => 4,
                'to_branch' => 9,
                'product_id' => 22,
                'quantity' => $quantity,
            ]);

            $response = $handler->transferStock($request, (new ResponseFactory())->createResponse(), ['id' => '4']);

            self::assertSame(400, $response->getStatusCode());
            self::assertFalse($db->inTransaction());
        }
    }

    public function testTransferRejectsSameBranchAndMismatchedSourcePath(): void
    {
        $handler = new StockTransferHandler(new PDO('sqlite::memory:'));
        $responseFactory = new ResponseFactory();

        $sameBranch = $handler->transferStock(
            $this->transferRequest([
                'from_branch' => 4,
                'to_branch' => 4,
                'product_id' => 22,
                'quantity' => 1,
            ]),
            $responseFactory->createResponse(),
            ['id' => '4']
        );
        self::assertSame(400, $sameBranch->getStatusCode());

        $mismatchedSource = $handler->transferStock(
            $this->transferRequest([
                'from_branch' => 7,
                'to_branch' => 9,
                'product_id' => 22,
                'quantity' => 1,
            ]),
            $responseFactory->createResponse(),
            ['id' => '4']
        );
        self::assertSame(400, $mismatchedSource->getStatusCode());
    }

    private function transferRequest(array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/branches/4/transfer')
            ->withAttribute('tenant_id', 3)
            ->withParsedBody($payload);
    }
}
