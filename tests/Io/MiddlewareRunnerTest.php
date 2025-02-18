<?php

namespace React\Tests\Http\Io;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Io\MiddlewareRunner;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\Http\Middleware\ProcessStack;
use React\Tests\Http\TestCase;
use function React\Async\await;
use function React\Promise\reject;

final class MiddlewareRunnerTest extends TestCase
{
    public function testEmptyMiddlewareStackThrowsException()
    {
        $request = new ServerRequest('GET', 'https://example.com/');
        $middlewares = [];
        $middlewareStack = new MiddlewareRunner($middlewares);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No middleware to run');
        $middlewareStack($request);
    }

    public function testMiddlewareHandlerReceivesTwoArguments()
    {
        $args = null;
        $middleware = new MiddlewareRunner([
            function (ServerRequestInterface $request, $next) use (&$args) {
                $args = func_num_args();
                return $next($request);
            },
            function (ServerRequestInterface $request) {
                return null;
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $middleware($request);

        $this->assertEquals(2, $args);
    }

    public function testFinalHandlerReceivesOneArgument()
    {
        $args = null;
        $middleware = new MiddlewareRunner([
            function (ServerRequestInterface $request) use (&$args) {
                $args = func_num_args();
                return null;
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $middleware($request);

        $this->assertEquals(1, $args);
    }

    public function testThrowsIfHandlerThrowsException()
    {
        $middleware = new MiddlewareRunner([
            function (ServerRequestInterface $request) {
                throw new \RuntimeException('hello');
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hello');
        $middleware($request);
    }

    public function testThrowsIfHandlerThrowsThrowable()
    {
        $middleware = new MiddlewareRunner([
            function (ServerRequestInterface $request) {
                throw new \Error('hello');
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('hello');
        $middleware($request);
    }

    public static function provideProcessStackMiddlewares()
    {
        $processStackA = new ProcessStack();
        $processStackB = new ProcessStack();
        $processStackC = new ProcessStack();
        $processStackD = new ProcessStack();
        $responseMiddleware = function () {
            return new Response(200);
        };
        yield [
            [
                $processStackA,
                $responseMiddleware,
            ],
            1,
        ];
        yield [
            [
                $processStackB,
                $processStackB,
                $responseMiddleware,
            ],
            2,
        ];
        yield [
            [
                $processStackC,
                $processStackC,
                $processStackC,
                $responseMiddleware,
            ],
            3,
        ];
        yield [
            [
                $processStackD,
                $processStackD,
                $processStackD,
                $processStackD,
                $responseMiddleware,
            ],
            4,
        ];
    }

    /**
     * @dataProvider provideProcessStackMiddlewares
     */
    public function testProcessStack(array $middlewares, $expectedCallCount)
    {
        // the ProcessStack middleware instances are stateful, so reset these
        // before running the test, to not fail with --repeat=100
        foreach ($middlewares as $middleware) {
            if ($middleware instanceof ProcessStack) {
                $middleware->reset();
            }
        }

        $request = new ServerRequest('GET', 'https://example.com/');
        $middlewareStack = new MiddlewareRunner($middlewares);

        $response = $middlewareStack($request);

        $this->assertTrue($response instanceof PromiseInterface);
        $response = await($response);

        $this->assertTrue($response instanceof ResponseInterface);
        $this->assertSame(200, $response->getStatusCode());

        foreach ($middlewares as $middleware) {
            if (!($middleware instanceof ProcessStack)) {
                continue;
            }

            $this->assertSame($expectedCallCount, $middleware->getCallCount());
        }
    }

    public static function provideErrorHandler()
    {
        yield [
            function (\Exception $e) {
                throw $e;
            }
        ];
        yield [
            function (\Exception $e) {
                return reject($e);
            }
        ];
    }

    /**
     * @dataProvider provideErrorHandler
     */
    public function testNextCanBeRunMoreThanOnceWithoutCorruptingTheMiddlewareStack($errorHandler)
    {
        $exception = new \RuntimeException(\exception::class);
        $retryCalled = 0;
        $error = null;
        $retry = function ($request, $next) use (&$error, &$retryCalled) {
            $promise = new Promise(function ($resolve) use ($request, $next) {
                $resolve($next($request));
            });

            return $promise->then(null, function ($et) use (&$error, $request, $next, &$retryCalled) {
                $retryCalled++;
                $error = $et;
                // the $next failed. discard $error and retry once again:
                return $next($request);
            });
        };

        $response = new Response();
        $called = 0;
        $runner = new MiddlewareRunner([
            $retry,
            function () use ($errorHandler, &$called, $response, $exception) {
                $called++;
                if ($called === 1) {
                    return $errorHandler($exception);
                }

                return $response;
            }
        ]);

        $request = new ServerRequest('GET', 'https://example.com/');

        $this->assertSame($response, await($runner($request)));
        $this->assertSame(1, $retryCalled);
        $this->assertSame(2, $called);
        $this->assertSame($exception, $error);
    }

    public function testMultipleRunsInvokeAllMiddlewareInCorrectOrder()
    {
        $requests = [
            new ServerRequest('GET', 'https://example.com/1'),
            new ServerRequest('GET', 'https://example.com/2'),
            new ServerRequest('GET', 'https://example.com/3')
        ];

        $receivedRequests = [];

        $middlewareRunner = new MiddlewareRunner([
            function (ServerRequestInterface $request, $next) use (&$receivedRequests) {
                $receivedRequests[] = 'middleware1: ' . $request->getUri();
                return $next($request);
            },
            function (ServerRequestInterface $request, $next) use (&$receivedRequests) {
                $receivedRequests[] = 'middleware2: ' . $request->getUri();
                return $next($request);
            },
            function (ServerRequestInterface $request) use (&$receivedRequests) {
                $receivedRequests[] = 'middleware3: ' . $request->getUri();
                return new Promise(function () { });
            }
        ]);

        foreach ($requests as $request) {
            $middlewareRunner($request);
        }

        $this->assertEquals(
            [
                'middleware1: https://example.com/1',
                'middleware2: https://example.com/1',
                'middleware3: https://example.com/1',
                'middleware1: https://example.com/2',
                'middleware2: https://example.com/2',
                'middleware3: https://example.com/2',
                'middleware1: https://example.com/3',
                'middleware2: https://example.com/3',
                'middleware3: https://example.com/3'
            ],
            $receivedRequests
        );
    }

    public static function provideUncommonMiddlewareArrayFormats()
    {
        yield [
            function () {
                $sequence = '';

                // Numeric index gap
                return [
                    0 => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'A';

                        return $next($request);
                    },
                    2 => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'B';

                        return $next($request);
                    },
                    3 => function () use (&$sequence) {
                        return new Response(200, [], $sequence . 'C');
                    },
                ];
            },
            'ABC',
        ];
        yield [
            function () {
                $sequence = '';

                // Reversed numeric indexes
                return [
                    2 => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'A';

                        return $next($request);
                    },
                    1 => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'B';

                        return $next($request);
                    },
                    0 => function () use (&$sequence) {
                        return new Response(200, [], $sequence . 'C');
                    },
                ];
            },
            'ABC',
        ];
        yield [
            function () {
                $sequence = '';

                // Associative array
                return [
                    'middleware1' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'A';

                        return $next($request);
                    },
                    'middleware2' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'B';

                        return $next($request);
                    },
                    'middleware3' => function () use (&$sequence) {
                        return new Response(200, [], $sequence . 'C');
                    },
                ];
            },
            'ABC',
        ];
        yield [
            function () {
                $sequence = '';

                // Associative array with empty or trimmable string keys
                return [
                    '' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'A';

                        return $next($request);
                    },
                    ' ' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'B';

                        return $next($request);
                    },
                    '  ' => function () use (&$sequence) {
                        return new Response(200, [], $sequence . 'C');
                    },
                ];
            },
            'ABC',
        ];
        yield [
            function () {
                $sequence = '';

                // Mixed array keys
                return [
                    '' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'A';

                        return $next($request);
                    },
                    0 => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'B';

                        return $next($request);
                    },
                    'foo' => function (ServerRequestInterface $request, $next) use (&$sequence) {
                        $sequence .= 'C';

                        return $next($request);
                    },
                    2 => function () use (&$sequence) {
                        return new Response(200, [], $sequence . 'D');
                    },
                ];
            },
            'ABCD',
        ];
    }

    /**
     * @dataProvider provideUncommonMiddlewareArrayFormats
     */
    public function testUncommonMiddlewareArrayFormats($middlewareFactory, $expectedSequence)
    {
        $request = new ServerRequest('GET', 'https://example.com/');
        $middlewareStack = new MiddlewareRunner($middlewareFactory());

        $response = $middlewareStack($request);

        $this->assertTrue($response instanceof ResponseInterface);
        $this->assertSame($expectedSequence, (string) $response->getBody());
    }

    public function testPendingNextRequestHandlersCanBeCalledConcurrently()
    {
        $called = 0;
        $middleware = new MiddlewareRunner([
            function (RequestInterface $request, $next) {
                $first = $next($request);
                $second = $next($request);

                return new Response();
            },
            function (RequestInterface $request) use (&$called) {
                ++$called;

                return new Promise(function () { });
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $response = $middleware($request);

        $this->assertTrue($response instanceof ResponseInterface);
        $this->assertEquals(2, $called);
    }

    public function testCancelPendingNextHandler()
    {
        $once = $this->expectCallableOnce();
        $middleware = new MiddlewareRunner([
            function (RequestInterface $request, $next) {
                $ret = $next($request);
                $ret->cancel();

                return $ret;
            },
            function (RequestInterface $request) use ($once) {
                return new Promise(function () { }, $once);
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $middleware($request);
    }

    public function testCancelResultingPromiseWillCancelPendingNextHandler()
    {
        $once = $this->expectCallableOnce();
        $middleware = new MiddlewareRunner([
            function (RequestInterface $request, $next) {
                return $next($request);
            },
            function (RequestInterface $request) use ($once) {
                return new Promise(function () { }, $once);
            }
        ]);

        $request = new ServerRequest('GET', 'http://example.com/');

        $promise = $middleware($request);

        $this->assertTrue($promise instanceof PromiseInterface && \method_exists($promise, 'cancel'));
        $promise->cancel();
    }
}
