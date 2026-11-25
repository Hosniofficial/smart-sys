<?php

declare(strict_types=1);

namespace App\Routing;

use Psr\Container\ContainerInterface;

final class ContainerHandlerProxy
{
    public function __construct(
        private ContainerInterface $container,
        private string $handlerClass
    ) {
    }

    public function __call(string $method, array $arguments): mixed
    {
        $handler = $this->container->get($this->handlerClass);
        if (!is_object($handler) || !is_callable([$handler, $method])) {
            throw new \BadMethodCallException(
                sprintf('Handler %s::%s is not callable.', $this->handlerClass, $method)
            );
        }

        return $handler->{$method}(...$arguments);
    }
}
