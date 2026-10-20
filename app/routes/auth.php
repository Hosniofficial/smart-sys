<?php

use App\Handlers\AuthHandler;
use App\Handlers\EmailVerificationHandler;
use App\Handlers\StrictSubscriptionHandler;
use App\Middleware\RequestRateLimiter;
use App\Middleware\StrictSubscriptionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
        $authHandler = $container->get(AuthHandler::class);
        $strictSubscriptionHandler = $container->get(StrictSubscriptionHandler::class);
        $emailVerificationHandler = $container->get(EmailVerificationHandler::class);
        $strictSubscriptionMiddleware = $container->get(StrictSubscriptionMiddleware::class);
        $rateLimiter = $container->get(RequestRateLimiter::class);

        $group->post('/login', [$authHandler, 'login'])
            ->add($rateLimiter);
        $group->post('/logout', [$authHandler, 'logout']);
        $group->post('/logout-all-devices', [$authHandler, 'logoutAllDevices']);
        $group->post('/refresh', [$authHandler, 'refreshToken'])
            ->add($rateLimiter);

        $group->post('/register', [$strictSubscriptionHandler, 'createSecureTrial'])
            ->add($strictSubscriptionMiddleware);

        $group->post('/verify-email', [$emailVerificationHandler, 'verifyEmail']);
        $group->post('/verify-email/resend', [$emailVerificationHandler, 'resendVerificationEmail']);

        $group->post('/forgot-password', [$emailVerificationHandler, 'forgotPassword'])
            ->add($rateLimiter);
        $group->post('/reset-password', [$emailVerificationHandler, 'resetPassword'])
            ->add($rateLimiter);

};
