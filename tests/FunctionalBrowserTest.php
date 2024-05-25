<?php

namespace React\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use function React\Async\await;
use function React\Promise\Stream\buffer;
use function React\Promise\Timer\timeout;

class FunctionalBrowserTest extends TestCase
{
    private $browser;
    private $base;

    /** @var ?SocketServer */
    private $socket;

    /**
     * @before
     */
    public function setUpBrowserAndServer()
    {
        $this->browser = new Browser();

        $http = new HttpServer(new StreamingRequestMiddleware(), function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();

            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            if ($path === '/get') {
                return new Response(
                    200,
                    [],
                    'hello'
                );
            }

            if ($path === '/redirect-to') {
                $params = $request->getQueryParams();
                return new Response(
                    302,
                    ['Location' => $params['url']]
                );
            }

            if ($path === '/basic-auth/user/pass') {
                return new Response(
                    $request->getHeaderLine('Authorization') === 'Basic dXNlcjpwYXNz' ? 200 : 401,
                    [],
                    ''
                );
            }

            if ($path === '/status/204') {
                return new Response(
                    204,
                    [],
                    ''
                );
            }

            if ($path === '/status/304') {
                return new Response(
                    304,
                    [],
                    'Not modified'
                );
            }

            if ($path === '/status/404') {
                return new Response(
                    404,
                    [],
                    ''
                );
            }

            if ($path === '/delay/10') {
                $timer = null;
                return new Promise(function ($resolve) use (&$timer) {
                    $timer = Loop::addTimer(10, function () use ($resolve) {
                        $resolve(new Response(
                            200,
                            [],
                            'hello'
                        ));
                    });
                }, function () use (&$timer) {
                    Loop::cancelTimer($timer);
                });
            }

            if ($path === '/post') {
                return new Promise(function ($resolve) use ($request, $headers) {
                    $body = $request->getBody();
                    assert($body instanceof ReadableStreamInterface);

                    $buffer = '';
                    $body->on('data', function ($data) use (&$buffer) {
                        $buffer .= $data;
                    });

                    $body->on('close', function () use (&$buffer, $resolve, $headers) {
                        $resolve(new Response(
                            200,
                            [],
                            json_encode([
                                'data' => $buffer,
                                'headers' => $headers
                            ])
                        ));
                    });
                });
            }

            if ($path === '/stream/1') {
                $stream = new ThroughStream();

                Loop::futureTick(function () use ($stream, $headers) {
                    $stream->end(json_encode([
                        'headers' => $headers
                    ]));
                });

                return new Response(
                    200,
                    [],
                    $stream
                );
            }

            var_dump($path);
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $http->listen($this->socket);

        $this->base = str_replace('tcp:', 'http:', $this->socket->getAddress()) . '/';
    }

    /**
     * @after
     */
    public function cleanUpSocketServer()
    {
        $this->socket->close();
        $this->socket = null;
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testSimpleRequest()
    {
        await($this->browser->get($this->base . 'get'));
    }

    public function testGetRequestWithRelativeAddressRejects()
    {
        $promise = $this->browser->get('delay');

        $this->setExpectedException('InvalidArgumentException', 'Invalid request URL given');
        await($promise);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGetRequestWithBaseAndRelativeAddressResolves()
    {
        await($this->browser->withBase($this->base)->get('get'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGetRequestWithBaseAndFullAddressResolves()
    {
        await($this->browser->withBase('http://example.com/')->get($this->base . 'get'));
    }

    public function testCancelGetRequestWillRejectRequest()
    {
        $promise = $this->browser->get($this->base . 'get');
        $promise->cancel();

        $this->setExpectedException('RuntimeException');
        await($promise);
    }

    public function testCancelRequestWithPromiseFollowerWillRejectRequest()
    {
        $promise = $this->browser->request('GET', $this->base . 'get')->then(function () {
            var_dump('noop');
        });
        $promise->cancel();

        $this->setExpectedException('RuntimeException');
        await($promise);
    }

    public function testRequestWithoutAuthenticationFails()
    {
        $this->setExpectedException('RuntimeException');
        await($this->browser->get($this->base . 'basic-auth/user/pass'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRequestWithAuthenticationSucceeds()
    {
        $base = str_replace('://', '://user:pass@', $this->base);

        await($this->browser->get($base . 'basic-auth/user/pass'));
    }

    /**
     * ```bash
     * $ curl -vL "http://httpbingo.org/redirect-to?url=http://user:pass@httpbingo.org/basic-auth/user/pass"
     * ```
     *
     * @doesNotPerformAssertions
     */
    public function testRedirectToPageWithAuthenticationSendsAuthenticationFromLocationHeader()
    {
        $target = str_replace('://', '://user:pass@', $this->base) . 'basic-auth/user/pass';

        await($this->browser->get($this->base . 'redirect-to?url=' . urlencode($target)));
    }

    /**
     * ```bash
     * $ curl -vL "http://unknown:invalid@httpbingo.org/redirect-to?url=http://user:pass@httpbingo.org/basic-auth/user/pass"
     * ```
     *
     * @doesNotPerformAssertions
     */
    public function testRedirectFromPageWithInvalidAuthToPageWithCorrectAuthenticationSucceeds()
    {
        $base = str_replace('://', '://unknown:invalid@', $this->base);
        $target = str_replace('://', '://user:pass@', $this->base) . 'basic-auth/user/pass';

        await($this->browser->get($base . 'redirect-to?url=' . urlencode($target)));
    }

    public function testCancelRedirectedRequestShouldReject()
    {
        $promise = $this->browser->get($this->base . 'redirect-to?url=delay%2F10');

        Loop::addTimer(0.1, function () use ($promise) {
            $promise->cancel();
        });

        $this->setExpectedException('RuntimeException', 'Request cancelled');
        await($promise);
    }

    public function testTimeoutDelayedResponseShouldReject()
    {
        $promise = $this->browser->withTimeout(0.1)->get($this->base . 'delay/10');

        $this->setExpectedException('RuntimeException', 'Request timed out after 0.1 seconds');
        await($promise);
    }

    public function testTimeoutDelayedResponseAfterStreamingRequestShouldReject()
    {
        $stream = new ThroughStream();
        $promise = $this->browser->withTimeout(0.1)->post($this->base . 'delay/10', [], $stream);
        $stream->end();

        $this->setExpectedException('RuntimeException', 'Request timed out after 0.1 seconds');
        await($promise);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testTimeoutFalseShouldResolveSuccessfully()
    {
        await($this->browser->withTimeout(false)->get($this->base . 'get'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRedirectRequestRelative()
    {
        await($this->browser->get($this->base . 'redirect-to?url=get'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRedirectRequestAbsolute()
    {
        await($this->browser->get($this->base . 'redirect-to?url=' . urlencode($this->base . 'get')));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testFollowingRedirectsFalseResolvesWithRedirectResult()
    {
        $browser = $this->browser->withFollowRedirects(false);

        await($browser->get($this->base . 'redirect-to?url=get'));
    }

    public function testFollowRedirectsZeroRejectsOnRedirect()
    {
        $browser = $this->browser->withFollowRedirects(0);

        $this->setExpectedException('RuntimeException');
        await($browser->get($this->base . 'redirect-to?url=get'));
    }

    public function testResponseStatus204ShouldResolveWithEmptyBody()
    {
        $response = await($this->browser->get($this->base . 'status/204'));
        $this->assertFalse($response->hasHeader('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    public function testResponseStatus304ShouldResolveWithEmptyBodyButContentLengthResponseHeader()
    {
        $response = await($this->browser->get($this->base . 'status/304'));
        $this->assertEquals('12', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGetRequestWithResponseBufferMatchedExactlyResolves()
    {
        $promise = $this->browser->withResponseBuffer(5)->get($this->base . 'get');

        await($promise);
    }

    public function testGetRequestWithResponseBufferExceededRejects()
    {
        $promise = $this->browser->withResponseBuffer(4)->get($this->base . 'get');

        $this->setExpectedException(
            'OverflowException',
            'Response body size of 5 bytes exceeds maximum of 4 bytes',
            defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 0
        );
        await($promise);
    }

    public function testGetRequestWithResponseBufferExceededDuringStreamingRejects()
    {
        $promise = $this->browser->withResponseBuffer(4)->get($this->base . 'stream/1');

        $this->setExpectedException(
            'OverflowException',
            'Response body size exceeds maximum of 4 bytes',
            defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 0
        );
        await($promise);
    }

    /**
     * @group internet
     * @doesNotPerformAssertions
     */
    public function testCanAccessHttps()
    {
        await($this->browser->get('https://www.google.com/'));
    }

    /**
     * @group internet
     */
    public function testVerifyPeerEnabledForBadSslRejects()
    {
        $connector = new Connector([
            'tls' => [
                'verify_peer' => true
            ]
        ]);

        $browser = new Browser($connector);

        $this->setExpectedException('RuntimeException');
        await($browser->get('https://self-signed.badssl.com/'));
    }

    /**
     * @group internet
     * @doesNotPerformAssertions
     */
    public function testVerifyPeerDisabledForBadSslResolves()
    {
        $connector = new Connector([
            'tls' => [
                'verify_peer' => false
            ]
        ]);

        $browser = new Browser($connector);

        await($browser->get('https://self-signed.badssl.com/'));
    }

    /**
     * @group internet
     */
    public function testInvalidPort()
    {
        $this->setExpectedException('RuntimeException');
        await($this->browser->get('http://www.google.com:443/'));
    }

    public function testErrorStatusCodeRejectsWithResponseException()
    {
        try {
            await($this->browser->get($this->base . 'status/404'));
            $this->fail();
        } catch (ResponseException $e) {
            $this->assertEquals(404, $e->getCode());

            $this->assertInstanceOf('Psr\Http\Message\ResponseInterface', $e->getResponse());
            $this->assertEquals(404, $e->getResponse()->getStatusCode());
        }
    }

    public function testErrorStatusCodeDoesNotRejectWithRejectErrorResponseFalse()
    {
        $response = await($this->browser->withRejectErrorResponse(false)->get($this->base . 'status/404'));

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testPostString()
    {
        $response = await($this->browser->post($this->base . 'post', [], 'hello world'));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
    }

    public function testRequestStreamReturnsResponseBodyUntilConnectionsEndsForHttp10()
    {
        $response = await($this->browser->withProtocolVersion('1.0')->get($this->base . 'stream/1'));

        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertFalse($response->hasHeader('Transfer-Encoding'));

        $this->assertStringStartsWith('{', (string) $response->getBody());
        $this->assertStringEndsWith('}', (string) $response->getBody());
    }

    public function testRequestStreamReturnsResponseWithTransferEncodingChunkedAndResponseBodyDecodedForHttp11()
    {
        $response = await($this->browser->get($this->base . 'stream/1'));

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));

        $this->assertStringStartsWith('{', (string) $response->getBody());
        $this->assertStringEndsWith('}', (string) $response->getBody());
    }

    public function testRequestStreamWithHeadRequestReturnsEmptyResponseBodWithTransferEncodingChunkedForHttp11()
    {
        $response = await($this->browser->head($this->base . 'stream/1'));

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));
        $this->assertEquals('', (string) $response->getBody());
    }

    public function testRequestStreamReturnsResponseWithResponseBodyUndecodedWhenResponseHasDoubleTransferEncoding()
    {
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) {
            $connection->on('data', function () use ($connection) {
                $connection->end("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked, chunked\r\nConnection: close\r\n\r\nhello");
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = await($this->browser->get($this->base . 'stream/1'));

        $socket->close();

        $this->assertEquals('1.1', $response->getProtocolVersion());

        $this->assertEquals('chunked, chunked', $response->getHeaderLine('Transfer-Encoding'));
        $this->assertEquals('hello', (string) $response->getBody());
    }

    public function testReceiveStreamAndExplicitlyCloseConnectionEvenWhenServerKeepsConnectionOpen()
    {
        $closed = new Deferred();
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) use ($closed) {
            $connection->on('data', function () use ($connection) {
                $connection->write("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            });
            $connection->on('close', function () use ($closed) {
                $closed->resolve(true);
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = await($this->browser->get($this->base . 'get', []));
        $this->assertEquals('hello', (string)$response->getBody());

        $ret = await(timeout($closed->promise(), 0.1));
        $this->assertTrue($ret);

        $socket->close();
    }

    public function testRequestWithConnectionCloseHeaderWillCreateNewConnectionForSecondRequestEvenWhenServerKeepsConnectionOpen()
    {
        $twice = $this->expectCallableOnce();
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) use ($socket, $twice) {
            $connection->on('data', function () use ($connection) {
                $connection->write("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            });

            $socket->on('connection', $twice);
            $socket->on('connection', function () use ($socket) {
                $socket->close();
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        // add `Connection: close` request header to disable HTTP keep-alive
        $this->browser = $this->browser->withHeader('Connection', 'close');

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testRequestWithHttp10WillCreateNewConnectionForSecondRequestEvenWhenServerKeepsConnectionOpen()
    {
        $twice = $this->expectCallableOnce();
        $socket = new SocketServer('127.0.0.1:0');
        $socket->on('connection', function (ConnectionInterface $connection) use ($socket, $twice) {
            $connection->on('data', function () use ($connection) {
                $connection->write("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            });

            $socket->on('connection', $twice);
            $socket->on('connection', function () use ($socket) {
                $socket->close();
            });
        });

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        // use HTTP/1.0 to disable HTTP keep-alive
        $this->browser = $this->browser->withProtocolVersion('1.0');

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testRequestWillReuseExistingConnectionForSecondRequestByDefault()
    {
        $this->socket->on('connection', $this->expectCallableOnce());

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testRequestWithHttp10AndConnectionKeepAliveHeaderWillReuseExistingConnectionForSecondRequest()
    {
        $this->socket->on('connection', $this->expectCallableOnce());

        $this->browser = $this->browser->withProtocolVersion('1.0');
        $this->browser = $this->browser->withHeader('Connection', 'keep-alive');

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());

        $response = await($this->browser->get($this->base . 'get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testRequestWithoutConnectionHeaderWillReuseExistingConnectionForRedirectedRequest()
    {
        $this->socket->on('connection', $this->expectCallableOnce());

        // remove default `Connection: close` request header to enable keep-alive
        $this->browser = $this->browser->withoutHeader('Connection');

        $response = await($this->browser->get($this->base . 'redirect-to?url=get'));
        assert($response instanceof ResponseInterface);
        $this->assertEquals('hello', (string)$response->getBody());
    }

    public function testPostStreamChunked()
    {
        $stream = new ThroughStream();

        Loop::addTimer(0.001, function () use ($stream) {
            $stream->end('hello world');
        });

        $response = await($this->browser->post($this->base . 'post', [], $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
        $this->assertFalse(isset($data['headers']['Content-Length']));
        $this->assertEquals('chunked', $data['headers']['Transfer-Encoding']);
    }

    public function testPostStreamKnownLength()
    {
        $stream = new ThroughStream();

        Loop::addTimer(0.001, function () use ($stream) {
            $stream->end('hello world');
        });

        $response = await($this->browser->post($this->base . 'post', ['Content-Length' => 11], $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('hello world', $data['data']);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testPostStreamWillStartSendingRequestEvenWhenBodyDoesNotEmitData()
    {
        $http = new HttpServer(new StreamingRequestMiddleware(), function (ServerRequestInterface $request) {
            return new Response(200);
        });
        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $stream = new ThroughStream();
        await($this->browser->post($this->base . 'post', [], $stream));

        $socket->close();
    }

    public function testPostStreamClosed()
    {
        $stream = new ThroughStream();
        $stream->close();

        $response = await($this->browser->post($this->base . 'post', [], $stream));
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('', $data['data']);
    }

    public function testSendsHttp11ByDefault()
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return new Response(
                200,
                [],
                $request->getProtocolVersion()
            );
        });
        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = await($this->browser->get($this->base));
        $this->assertEquals('1.1', (string)$response->getBody());

        $socket->close();
    }

    public function testSendsExplicitHttp10Request()
    {
        $http = new HttpServer(function (ServerRequestInterface $request) {
            return new Response(
                200,
                [],
                $request->getProtocolVersion()
            );
        });
        $socket = new SocketServer('127.0.0.1:0');
        $http->listen($socket);

        $this->base = str_replace('tcp:', 'http:', $socket->getAddress()) . '/';

        $response = await($this->browser->withProtocolVersion('1.0')->get($this->base));
        $this->assertEquals('1.0', (string)$response->getBody());

        $socket->close();
    }

    public function testHeadRequestReceivesResponseWithEmptyBodyButWithContentLengthResponseHeader()
    {
        $response = await($this->browser->head($this->base . 'get'));
        $this->assertEquals('5', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(0, $body->getSize());
        $this->assertEquals('', (string) $body);
    }

    public function testRequestStreamingGetReceivesResponseWithStreamingBodyAndKnownSize()
    {
        $response = await($this->browser->requestStreaming('GET', $this->base . 'get'));
        $this->assertEquals('5', $response->getHeaderLine('Content-Length'));

        $body = $response->getBody();
        $this->assertEquals(5, $body->getSize());
        $this->assertEquals('', (string) $body);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $body);
    }

    public function testRequestStreamingGetReceivesResponseWithStreamingBodyAndUnknownSizeFromStreamingEndpoint()
    {
        $response = await($this->browser->requestStreaming('GET', $this->base . 'stream/1'));
        $this->assertFalse($response->hasHeader('Content-Length'));

        $body = $response->getBody();
        $this->assertNull($body->getSize());
        $this->assertEquals('', (string) $body);
        $this->assertInstanceOf('React\Stream\ReadableStreamInterface', $body);
    }

    public function testRequestStreamingGetReceivesStreamingResponseBody()
    {
        $buffer = await(
            $this->browser->requestStreaming('GET', $this->base . 'get')->then(function (ResponseInterface $response) {
                return buffer($response->getBody());
            })
        );

        $this->assertEquals('hello', $buffer);
    }

    public function testRequestStreamingGetReceivesStreamingResponseBodyEvenWhenResponseBufferExceeded()
    {
        $buffer = await(
            $this->browser->withResponseBuffer(4)->requestStreaming('GET', $this->base . 'get')->then(function (ResponseInterface $response) {
                return buffer($response->getBody());
            })
        );

        $this->assertEquals('hello', $buffer);
    }
}
