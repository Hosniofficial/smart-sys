<?php

use App\Handlers\AuthHandler;
use App\Handlers\EmailVerificationHandler;
use App\Handlers\StrictSubscriptionHandler;
use DI\Container;
use Slim\Routing\RouteCollectorProxy;

return function (RouteCollectorProxy $group, Container $container): void {
    // Rate limiting for public auth routes is applied once by config/middleware.php.
    $group->post('/login', AuthHandler::class . ':login');
    $group->post('/logout', AuthHandler::class . ':logout');
    $group->post('/logout-all-devices', AuthHandler::class . ':logoutAllDevices');
    $group->post('/refresh', AuthHandler::class . ':refreshToken');

    $group->post('/register', StrictSubscriptionHandler::class . ':createSecureTrial');

    $group->post('/verify-email', EmailVerificationHandler::class . ':verifyEmail');
    $group->post('/verify-email/resend', EmailVerificationHandler::class . ':resendVerificationEmail');

    $group->post('/forgot-password', EmailVerificationHandler::class . ':forgotPassword');
    $group->post('/reset-password', EmailVerificationHandler::class . ':resetPassword');

};
