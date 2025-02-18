<?php

namespace React\Tests\Http\Io;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Io\Clock;
use React\Http\Io\RequestHeaderParser;
use React\Socket\Connection;
use React\Stream\ReadableStreamInterface;
use React\Tests\Http\TestCase;

class RequestHeaderParserTest extends TestCase
{
    public function testSplitShouldHappenOnDoubleCrlf()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();

        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\n"]);
        $connection->emit('data', ["Host: example.com:80\r\n"]);
        $connection->emit('data', ["Connection: close\r\n"]);

        $parser->removeAllListeners();
        $parser->on('headers', $this->expectCallableOnce());

        $connection->emit('data', ["\r\n"]);
    }

    public function testFeedInOneGo()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableOnce());

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createGetRequest();
        $connection->emit('data', [$data]);
    }

    public function testFeedTwoRequestsOnSeparateConnections()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $called = 0;
        $parser->on('headers', function () use (&$called) {
            ++$called;
        });

        $connection1 = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $connection2 = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection1);
        $parser->handle($connection2);

        $data = $this->createGetRequest();
        $connection1->emit('data', [$data]);
        $connection2->emit('data', [$data]);

        $this->assertEquals(2, $called);
    }

    public function testHeadersEventShouldEmitRequestAndConnection()
    {
        $request = null;
        $conn = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', function ($parsedRequest, $connection) use (&$request, &$conn) {
            $request = $parsedRequest;
            $conn = $connection;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createGetRequest();
        $connection->emit('data', [$data]);

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEquals('http://example.com/', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['Host' => ['example.com'], 'Connection' => ['close']], $request->getHeaders());

        $this->assertSame($connection, $conn);
    }

    public function testHeadersEventShouldEmitRequestWhichShouldEmitEndForStreamingBodyWithoutContentLengthFromInitialRequestBody()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $ended = false;
        $parser->on('headers', function (ServerRequestInterface $request) use (&$ended) {
            $body = $request->getBody();
            $this->assertInstanceOf(ReadableStreamInterface::class, $body);

            $body->on('end', function () use (&$ended) {
                $ended = true;
            });
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = "GET / HTTP/1.0\r\n\r\n";
        $connection->emit('data', [$data]);

        $this->assertTrue($ended);
    }

    public function testHeadersEventShouldEmitRequestWhichShouldEmitStreamingBodyDataFromInitialRequestBody()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $buffer = '';
        $parser->on('headers', function (ServerRequestInterface $request) use (&$buffer) {
            $body = $request->getBody();
            $this->assertInstanceOf(ReadableStreamInterface::class, $body);

            $body->on('data', function ($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });
            $body->on('end', function () use (&$buffer) {
                $buffer .= '.';
            });
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = "POST / HTTP/1.0\r\nContent-Length: 11\r\n\r\n";
        $data .= 'RANDOM DATA';
        $connection->emit('data', [$data]);

        $this->assertSame('RANDOM DATA.', $buffer);
    }

    public function testHeadersEventShouldEmitRequestWhichShouldEmitStreamingBodyWithPlentyOfDataFromInitialRequestBody()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $buffer = '';
        $parser->on('headers', function (ServerRequestInterface $request) use (&$buffer) {
            $body = $request->getBody();
            $this->assertInstanceOf(ReadableStreamInterface::class, $body);

            $body->on('data', function ($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $size = 10000;
        $data = "POST / HTTP/1.0\r\nContent-Length: $size\r\n\r\n";
        $data .= str_repeat('x', $size);
        $connection->emit('data', [$data]);

        $this->assertSame($size, strlen($buffer));
    }

    public function testHeadersEventShouldEmitRequestWhichShouldNotEmitStreamingBodyDataWithoutContentLengthFromInitialRequestBody()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $buffer = '';
        $parser->on('headers', function (ServerRequestInterface $request) use (&$buffer) {
            $body = $request->getBody();
            $this->assertInstanceOf(ReadableStreamInterface::class, $body);

            $body->on('data', function ($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = "POST / HTTP/1.0\r\n\r\n";
        $data .= 'RANDOM DATA';
        $connection->emit('data', [$data]);

        $this->assertSame('', $buffer);
    }

    public function testHeadersEventShouldEmitRequestWhichShouldEmitStreamingBodyDataUntilContentLengthBoundaryFromInitialRequestBody()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $buffer = '';
        $parser->on('headers', function (ServerRequestInterface $request) use (&$buffer) {
            $body = $request->getBody();
            $this->assertInstanceOf(ReadableStreamInterface::class, $body);

            $body->on('data', function ($chunk) use (&$buffer) {
                $buffer .= $chunk;
            });
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = "POST / HTTP/1.0\r\nContent-Length: 6\r\n\r\n";
        $data .= 'RANDOM DATA';
        $connection->emit('data', [$data]);

        $this->assertSame('RANDOM', $buffer);
    }

    public function testHeadersEventShouldParsePathAndQueryString()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = $this->createAdvancedPostRequest();
        $connection->emit('data', [$data]);

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertEquals('http://example.com/foo?bar=baz', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $headers = [
            'Host' => ['example.com'],
            'User-Agent' => ['react/alpha'],
            'Connection' => ['close']
        ];
        $this->assertSame($headers, $request->getHeaders());
    }

    public function testHeaderEventWithShouldApplyDefaultAddressFromLocalConnectionAddress()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tcp://127.1.1.1:8000');
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\n\r\n"]);

        $this->assertEquals('http://127.1.1.1:8000/foo', $request->getUri());
        $this->assertFalse($request->hasHeader('Host'));
    }

    public function testHeaderEventViaHttpsShouldApplyHttpsSchemeFromLocalTlsConnectionAddress()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tls://127.1.1.1:8000');
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $this->assertEquals('https://example.com/foo', $request->getUri());
        $this->assertEquals('example.com', $request->getHeaderLine('Host'));
    }

    public function testHeaderOverflowShouldEmitError()
    {
        $error = null;
        $passedConnection = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message, $connection) use (&$error, &$passedConnection) {
            $error = $message;
            $passedConnection = $connection;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $data = str_repeat('A', 8193);
        $connection->emit('data', [$data]);

        $this->assertInstanceOf(\OverflowException::class, $error);
        $this->assertSame('Maximum header size of 8192 exceeded.', $error->getMessage());
        $this->assertSame($connection, $passedConnection);
    }

    public function testInvalidEmptyRequestHeadersParseException()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Unable to parse invalid request-line', $error->getMessage());
    }

    public function testInvalidMalformedRequestLineParseException()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET /\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Unable to parse invalid request-line', $error->getMessage());
    }

    public function testInvalidMalformedRequestHeadersThrowsParseException()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost : yes\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Unable to parse invalid request header fields', $error->getMessage());
    }

    public function testInvalidMalformedRequestHeadersWhitespaceThrowsParseException()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: yes\rFoo: bar\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Unable to parse invalid request header fields', $error->getMessage());
    }

    public function testInvalidAbsoluteFormSchemeEmitsError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET tcp://example.com:80/ HTTP/1.0\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testOriginFormWithSchemeSeparatorInParam()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('error', $this->expectCallableNever());
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET /somepath?param=http://example.com HTTP/1.1\r\nHost: localhost\r\n\r\n"]);

        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEquals('http://localhost/somepath?param=http://example.com', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $headers = [
            'Host' => ['localhost']
        ];
        $this->assertSame($headers, $request->getHeaders());
    }

    public function testUriStartingWithColonSlashSlashFails()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET ://example.com:80/ HTTP/1.0\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testInvalidAbsoluteFormWithFragmentEmitsError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET http://example.com:80/#home HTTP/1.0\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testInvalidHeaderContainsFullUri()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: http://user:pass@host/\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Invalid Host header value', $error->getMessage());
    }

    public function testInvalidAbsoluteFormWithHostHeaderEmpty()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET http://example.com/ HTTP/1.1\r\nHost: \r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('Invalid Host header value', $error->getMessage());
    }

    public function testInvalidConnectRequestWithNonAuthorityForm()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["CONNECT http://example.com:8080/ HTTP/1.1\r\nHost: example.com:8080\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame('CONNECT method MUST use authority-form request target', $error->getMessage());
    }

    public function testInvalidHttpVersion()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.2\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(505, $error->getCode());
        $this->assertSame('Received request with invalid protocol version', $error->getMessage());
    }

    public function testInvalidContentLengthRequestHeaderWillEmitError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length: foo\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(400, $error->getCode());
        $this->assertSame('The value of `Content-Length` is not valid', $error->getMessage());
    }

    public function testInvalidRequestWithMultipleContentLengthRequestHeadersWillEmitError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nContent-Length: 5\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(400, $error->getCode());
        $this->assertSame('The value of `Content-Length` is not valid', $error->getMessage());
    }

    public function testInvalidTransferEncodingRequestHeaderWillEmitError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: foo\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(501, $error->getCode());
        $this->assertSame('Only chunked-encoding is allowed for Transfer-Encoding', $error->getMessage());
    }

    public function testInvalidRequestWithBothTransferEncodingAndContentLengthWillEmitError()
    {
        $error = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\nContent-Length: 0\r\n\r\n"]);

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(400, $error->getCode());
        $this->assertSame('Using both `Transfer-Encoding: chunked` and `Content-Length` is not allowed', $error->getMessage());
    }

    public function testServerParamsWillBeSetOnHttpsRequest()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);
        $clock->expects($this->once())->method('now')->willReturn(1652972091.3958);

        $parser = new RequestHeaderParser($clock);

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress', 'getRemoteAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tls://127.1.1.1:8000');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tls://192.168.1.1:8001');
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $serverParams = $request->getServerParams();

        $this->assertEquals('on', $serverParams['HTTPS']);
        $this->assertEquals(1652972091, $serverParams['REQUEST_TIME']);
        $this->assertEquals(1652972091.3958, $serverParams['REQUEST_TIME_FLOAT']);

        $this->assertEquals('127.1.1.1', $serverParams['SERVER_ADDR']);
        $this->assertEquals('8000', $serverParams['SERVER_PORT']);

        $this->assertEquals('192.168.1.1', $serverParams['REMOTE_ADDR']);
        $this->assertEquals('8001', $serverParams['REMOTE_PORT']);
    }

    public function testServerParamsWillBeSetOnHttpRequest()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);
        $clock->expects($this->once())->method('now')->willReturn(1652972091.3958);

        $parser = new RequestHeaderParser($clock);

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress', 'getRemoteAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tcp://127.1.1.1:8000');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://192.168.1.1:8001');
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $serverParams = $request->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $serverParams);
        $this->assertEquals(1652972091, $serverParams['REQUEST_TIME']);
        $this->assertEquals(1652972091.3958, $serverParams['REQUEST_TIME_FLOAT']);

        $this->assertEquals('127.1.1.1', $serverParams['SERVER_ADDR']);
        $this->assertEquals('8000', $serverParams['SERVER_PORT']);

        $this->assertEquals('192.168.1.1', $serverParams['REMOTE_ADDR']);
        $this->assertEquals('8001', $serverParams['REMOTE_PORT']);
    }

    public function testServerParamsWillNotSetRemoteAddressForUnixDomainSockets()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);
        $clock->expects($this->once())->method('now')->willReturn(1652972091.3958);

        $parser = new RequestHeaderParser($clock);

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress', 'getRemoteAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('unix://./server.sock');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn(null);
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $serverParams = $request->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $serverParams);
        $this->assertEquals(1652972091, $serverParams['REQUEST_TIME']);
        $this->assertEquals(1652972091.3958, $serverParams['REQUEST_TIME_FLOAT']);

        $this->assertArrayNotHasKey('SERVER_ADDR', $serverParams);
        $this->assertArrayNotHasKey('SERVER_PORT', $serverParams);

        $this->assertArrayNotHasKey('REMOTE_ADDR', $serverParams);
        $this->assertArrayNotHasKey('REMOTE_PORT', $serverParams);
    }

    public function testServerParamsWontBeSetOnMissingUrls()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);
        $clock->expects($this->once())->method('now')->willReturn(1652972091.3958);

        $parser = new RequestHeaderParser($clock);

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $serverParams = $request->getServerParams();

        $this->assertEquals(1652972091, $serverParams['REQUEST_TIME']);
        $this->assertEquals(1652972091.3958, $serverParams['REQUEST_TIME_FLOAT']);

        $this->assertArrayNotHasKey('SERVER_ADDR', $serverParams);
        $this->assertArrayNotHasKey('SERVER_PORT', $serverParams);

        $this->assertArrayNotHasKey('REMOTE_ADDR', $serverParams);
        $this->assertArrayNotHasKey('REMOTE_PORT', $serverParams);
    }

    public function testServerParamsWillBeReusedForMultipleRequestsFromSameConnection()
    {
        $clock = $this->createMock(Clock::class);
        $clock->expects($this->exactly(2))->method('now')->willReturn(1652972091.3958);

        $parser = new RequestHeaderParser($clock);

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress', 'getRemoteAddress'])->getMock();
        $connection->expects($this->once())->method('getLocalAddress')->willReturn('tcp://127.1.1.1:8000');
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://192.168.1.1:8001');

        $parser->handle($connection);
        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $request = null;
        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $parser->handle($connection);
        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        assert($request instanceof ServerRequestInterface);
        $serverParams = $request->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $serverParams);
        $this->assertEquals(1652972091, $serverParams['REQUEST_TIME']);
        $this->assertEquals(1652972091.3958, $serverParams['REQUEST_TIME_FLOAT']);

        $this->assertEquals('127.1.1.1', $serverParams['SERVER_ADDR']);
        $this->assertEquals('8000', $serverParams['SERVER_PORT']);

        $this->assertEquals('192.168.1.1', $serverParams['REMOTE_ADDR']);
        $this->assertEquals('8001', $serverParams['REMOTE_PORT']);
    }

    public function testServerParamsWillBeRememberedUntilConnectionIsClosed()
    {
        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(['getLocalAddress', 'getRemoteAddress'])->getMock();

        $parser->handle($connection);
        $connection->emit('data', ["GET /foo HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $ref = new \ReflectionProperty($parser, 'connectionParams');
        $ref->setAccessible(true);

        $this->assertCount(1, $ref->getValue($parser));

        $connection->emit('close');
        $this->assertEquals([], $ref->getValue($parser));
    }

    public function testQueryParmetersWillBeSet()
    {
        $request = null;

        $clock = $this->createMock(Clock::class);

        $parser = new RequestHeaderParser($clock);

        $parser->on('headers', function ($parsedRequest) use (&$request) {
            $request = $parsedRequest;
        });

        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->setMethods(null)->getMock();
        $parser->handle($connection);

        $connection->emit('data', ["GET /foo.php?hello=world&test=this HTTP/1.0\r\nHost: example.com\r\n\r\n"]);

        $queryParams = $request->getQueryParams();

        $this->assertEquals('world', $queryParams['hello']);
        $this->assertEquals('this', $queryParams['test']);
    }

    private function createGetRequest()
    {
        $data = "GET / HTTP/1.1\r\n";
        $data .= "Host: example.com\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }

    private function createAdvancedPostRequest()
    {
        $data = "POST /foo?bar=baz HTTP/1.1\r\n";
        $data .= "Host: example.com\r\n";
        $data .= "User-Agent: react/alpha\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }
}
