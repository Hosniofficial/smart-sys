<?php

use App\Handlers\AuthHandler;
use App\Handlers\EmailVerificationHandler;
use App\Handlers\StrictSubscriptionHandler;
use App\Middleware\RequestRateLimiter;
use App\Middleware\StrictSubscriptionMiddleware;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
    $group->post('/login', AuthHandler::class . ':login')
        ->add(RequestRateLimiter::class);
    $group->post('/logout', AuthHandler::class . ':logout');
    $group->post('/logout-all-devices', AuthHandler::class . ':logoutAllDevices');
    $group->post('/refresh', AuthHandler::class . ':refreshToken')
        ->add(RequestRateLimiter::class);

    $group->post('/register', StrictSubscriptionHandler::class . ':createSecureTrial')
        ->add(StrictSubscriptionMiddleware::class);

    $group->post('/verify-email', EmailVerificationHandler::class . ':verifyEmail');
    $group->post('/verify-email/resend', EmailVerificationHandler::class . ':resendVerificationEmail');

    $group->post('/forgot-password', EmailVerificationHandler::class . ':forgotPassword')
        ->add(RequestRateLimiter::class);
    $group->post('/reset-password', EmailVerificationHandler::class . ':resetPassword')
        ->add(RequestRateLimiter::class);

};
