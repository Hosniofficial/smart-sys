<?php

declare(strict_types=1);

use App\Handlers\StrictSubscriptionHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class StrictSubscriptionRegistrationTest extends TestCase
{
    public function testMissingBusinessOwnerNameReturnsBadRequest(): void
    {
        $handler = new StrictSubscriptionHandler(new PDO('sqlite::memory:'));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/register')
            ->withParsedBody([
                'username' => 'new_owner',
                'email' => 'owner@example.test',
                'password' => 'goodpass123',
            ]);

        $response = $handler->createSecureTrial($request, new Response());

        self::assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('error', $payload['status'] ?? null);
        self::assertSame("Field 'full_name' is required", $payload['message'] ?? null);
    }
}
