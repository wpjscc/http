<?php

namespace React\Tests\Http\Message;

use Psr\Http\Message\StreamInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\ServerRequest;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Tests\Http\TestCase;

class ServerRequestTest extends TestCase
{
    private $request;

    /**
     * @before
     */
    public function setUpRequest()
    {
        $this->request = new ServerRequest('GET', 'http://localhost');
    }

    public function testGetNoAttributes()
    {
        $this->assertEquals([], $this->request->getAttributes());
    }

    public function testWithAttribute()
    {
        $request = $this->request->withAttribute('hello', 'world');

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['hello' => 'world'], $request->getAttributes());
    }

    public function testGetAttribute()
    {
        $request = $this->request->withAttribute('hello', 'world');

        $this->assertNotSame($request, $this->request);
        $this->assertEquals('world', $request->getAttribute('hello'));
    }

    public function testGetDefaultAttribute()
    {
        $request = $this->request->withAttribute('hello', 'world');

        $this->assertNotSame($request, $this->request);
        $this->assertNull($request->getAttribute('hi', null));
    }

    public function testWithoutAttribute()
    {
        $request = $this->request->withAttribute('hello', 'world');
        $request = $request->withAttribute('test', 'nice');

        $request = $request->withoutAttribute('hello');

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'nice'], $request->getAttributes());
    }

    public function testGetQueryParamsFromConstructorUri()
    {
        $this->request = new ServerRequest('GET', 'http://localhost/?test=world');

        $this->assertEquals(['test' => 'world'], $this->request->getQueryParams());
    }

    public function testWithCookieParams()
    {
        $request = $this->request->withCookieParams(['test' => 'world']);

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'world'], $request->getCookieParams());
    }

    public function testGetQueryParamsFromConstructorUriUrlencoded()
    {
        $this->request = new ServerRequest('GET', 'http://localhost/?test=hello+world%21');

        $this->assertEquals(['test' => 'hello world!'], $this->request->getQueryParams());
    }

    public function testWithQueryParams()
    {
        $request = $this->request->withQueryParams(['test' => 'world']);

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'world'], $request->getQueryParams());
    }

    public function testWithQueryParamsWithoutSpecialEncoding()
    {
        $request = $this->request->withQueryParams(['test' => 'hello world!']);

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'hello world!'], $request->getQueryParams());
    }

    public function testWithUploadedFiles()
    {
        $request = $this->request->withUploadedFiles(['test' => 'world']);

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'world'], $request->getUploadedFiles());
    }

    public function testWithParsedBody()
    {
        $request = $this->request->withParsedBody(['test' => 'world']);

        $this->assertNotSame($request, $this->request);
        $this->assertEquals(['test' => 'world'], $request->getParsedBody());
    }

    public function testServerRequestParameter()
    {
        $body = 'hello=world';
        $request = new ServerRequest(
            'POST',
            'http://127.0.0.1',
            ['Content-Length' => strlen($body)],
            $body,
            '1.0',
            ['SERVER_ADDR' => '127.0.0.1']
        );

        $serverParams = $request->getServerParams();
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://127.0.0.1', $request->getUri());
        $this->assertEquals('11', $request->getHeaderLine('Content-Length'));
        $this->assertEquals('hello=world', $request->getBody());
        $this->assertEquals('1.0', $request->getProtocolVersion());
        $this->assertEquals('127.0.0.1', $serverParams['SERVER_ADDR']);
    }

    public function testParseSingleCookieNameValuePairWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'world'], $cookies);
    }

    public function testParseMultipleCookieNameValuePairWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world; test=abc']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'world', 'test' => 'abc'], $cookies);
    }

    public function testParseMultipleCookieHeadersAreNotAllowedAndWillReturnEmptyArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => ['hello=world', 'test=abc']]
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals([], $cookies);
    }

    public function testMultipleCookiesWithSameNameWillReturnLastValue()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world; hello=abc']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'abc'], $cookies);
    }

    public function testOtherEqualSignsWillBeAddedToValueAndWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world=test=php']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'world=test=php'], $cookies);
    }

    public function testSingleCookieValueInCookiesReturnsEmptyArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'world']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals([], $cookies);
    }

    public function testSingleMutlipleCookieValuesReturnsEmptyArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'world; test']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals([], $cookies);
    }

    public function testSingleValueIsValidInMultipleValueCookieWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'world; test=php']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['test' => 'php'], $cookies);
    }

    public function testUrlEncodingForValueWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world%21; test=100%25%20coverage']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'world!', 'test' => '100% coverage'], $cookies);
    }

    public function testUrlEncodingForKeyWillReturnValidArray()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'react%3Bphp=is%20great']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['react%3Bphp' => 'is great'], $cookies);
    }

    public function testCookieWithoutSpaceAfterSeparatorWillBeAccepted()
    {
        $this->request = new ServerRequest(
            'GET',
            'http://localhost',
            ['Cookie' => 'hello=world;react=php']
        );

        $cookies = $this->request->getCookieParams();
        $this->assertEquals(['hello' => 'world', 'react' => 'php'], $cookies);
    }

    public function testConstructWithStringRequestBodyReturnsStringBodyWithAutomaticSize()
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost',
            [],
            'foo'
        );

        $body = $request->getBody();
        $this->assertSame(3, $body->getSize());
        $this->assertEquals('foo', (string) $body);
    }

    public function testConstructWithHttpBodyStreamReturnsBodyAsIs()
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost',
            [],
            $body = new HttpBodyStream(new ThroughStream(), 100)
        );

        $this->assertSame($body, $request->getBody());
    }

    public function testConstructWithStreamingRequestBodyReturnsBodyWhichImplementsReadableStreamInterfaceWithSizeZeroDefault()
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost',
            [],
            new ThroughStream()
        );

        $body = $request->getBody();
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertInstanceOf(ReadableStreamInterface::class, $body);
        $this->assertSame(0, $body->getSize());
    }

    public function testConstructWithStreamingRequestBodyReturnsBodyWithSizeFromContentLengthHeader()
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost',
            [
                'Content-Length' => 100
            ],
            new ThroughStream()
        );

        $body = $request->getBody();
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertInstanceOf(ReadableStreamInterface::class, $body);
        $this->assertSame(100, $body->getSize());
    }

    public function testConstructWithStreamingRequestBodyReturnsBodyWithSizeUnknownForTransferEncodingChunked()
    {
        $request = new ServerRequest(
            'GET',
            'http://localhost',
            [
                'Transfer-Encoding' => 'Chunked'
            ],
            new ThroughStream()
        );

        $body = $request->getBody();
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertInstanceOf(ReadableStreamInterface::class, $body);
        $this->assertNull($body->getSize());
    }

    public function testConstructWithFloatRequestBodyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServerRequest(
            'GET',
            'http://localhost',
            [],
            1.0
        );
    }

    public function testConstructWithResourceRequestBodyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServerRequest(
            'GET',
            'http://localhost',
            [],
            tmpfile()
        );
    }

    public function testParseMessageWithSimpleGetRequest()
    {
        $request = ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: example.com\r\n", []);

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://example.com/', (string) $request->getUri());
        $this->assertEquals('1.1', $request->getProtocolVersion());
    }

    public function testParseMessageWithHttp10RequestWithoutHost()
    {
        $request = ServerRequest::parseMessage("GET / HTTP/1.0\r\n", []);

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('http://127.0.0.1/', (string) $request->getUri());
        $this->assertEquals('1.0', $request->getProtocolVersion());
    }

    public function testParseMessageWithOptionsMethodWithAsteriskFormRequestTarget()
    {
        $request = ServerRequest::parseMessage("OPTIONS * HTTP/1.1\r\nHost: example.com\r\n", []);

        $this->assertEquals('OPTIONS', $request->getMethod());
        $this->assertEquals('*', $request->getRequestTarget());
        $this->assertEquals('1.1', $request->getProtocolVersion());
        $this->assertEquals('http://example.com', (string) $request->getUri());
    }

    public function testParseMessageWithConnectMethodWithAuthorityFormRequestTarget()
    {
        $request = ServerRequest::parseMessage("CONNECT example.com:80 HTTP/1.1\r\nHost: example.com:80\r\n", []);

        $this->assertEquals('CONNECT', $request->getMethod());
        $this->assertEquals('example.com:80', $request->getRequestTarget());
        $this->assertEquals('1.1', $request->getProtocolVersion());
        $this->assertEquals('http://example.com', (string) $request->getUri());
    }

    public function testParseMessageWithInvalidHttp11RequestWithoutHostThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\n", []);
    }

    public function testParseMessageWithInvalidHttpProtocolVersionThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.2\r\n", []);
    }

    public function testParseMessageWithInvalidProtocolThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / CUSTOM/1.1\r\n", []);
    }

    public function testParseMessageWithInvalidHostHeaderWithoutValueThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost\r\n", []);
    }

    public function testParseMessageWithInvalidHostHeaderSyntaxThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: ///\r\n", []);
    }

    public function testParseMessageWithInvalidHostHeaderWithSchemeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: http://localhost\r\n", []);
    }

    public function testParseMessageWithInvalidHostHeaderWithQueryThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: localhost?foo\r\n", []);
    }

    public function testParseMessageWithInvalidHostHeaderWithFragmentThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: localhost#foo\r\n", []);
    }

    public function testParseMessageWithInvalidContentLengthHeaderThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length:\r\n", []);
    }

    public function testParseMessageWithInvalidTransferEncodingHeaderThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding:\r\n", []);
    }

    public function testParseMessageWithInvalidBothContentLengthHeaderAndTransferEncodingHeaderThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\nTransfer-Encoding: chunked\r\n", []);
    }

    public function testParseMessageWithInvalidEmptyHostHeaderWithAbsoluteFormRequestTargetThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET http://example.com/ HTTP/1.1\r\nHost: \r\n", []);
    }

    public function testParseMessageWithInvalidConnectMethodNotUsingAuthorityFormThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("CONNECT / HTTP/1.1\r\nHost: localhost\r\n", []);
    }

    public function testParseMessageWithInvalidRequestTargetAsteriskFormThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRequest::parseMessage("GET * HTTP/1.1\r\nHost: localhost\r\n", []);
    }
}
