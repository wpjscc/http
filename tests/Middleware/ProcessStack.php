<?php

namespace React\Tests\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use function React\Promise\resolve;

final class ProcessStack
{
    /**
     * @var int
     */
    private $callCount = 0;

    public function __invoke(ServerRequestInterface $request, $stack)
    {
        $this->callCount++;
        return resolve($stack($request));
    }

    /**
     * @return int
     */
    public function getCallCount()
    {
        return $this->callCount;
    }

    public function reset()
    {
        $this->callCount = 0;
    }
}
