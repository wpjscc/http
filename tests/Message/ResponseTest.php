<?php

namespace React\Tests\Http\Message;

use Psr\Http\Message\StreamInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Tests\Http\TestCase;

class ResponseTest extends TestCase
{
    public function testConstructWithStringBodyWillReturnStreamInstance()
    {
        $response = new Response(200, [], 'hello');
        $body = $response->getBody();

        /** @var \Psr\Http\Message\StreamInterface $body */
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertEquals('hello', (string) $body);
    }

    public function testConstructWithStreamingBodyWillReturnReadableBodyStream()
    {
        $response = new Response(200, [], new ThroughStream());
        $body = $response->getBody();

        /** @var \Psr\Http\Message\StreamInterface $body */
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertInstanceof(ReadableStreamInterface::class, $body);
        $this->assertInstanceOf(HttpBodyStream::class, $body);
        $this->assertNull($body->getSize());
    }

    public function testConstructWithHttpBodyStreamReturnsBodyAsIs()
    {
        $response = new Response(
            200,
            [],
            $body = new HttpBodyStream(new ThroughStream(), 100)
        );

        $this->assertSame($body, $response->getBody());
    }

    public function testFloatBodyWillThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(200, [], 1.0);
    }

    public function testResourceBodyWillThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(200, [], tmpfile());
    }

    public function testWithStatusReturnsNewInstanceWhenStatusIsChanged()
    {
        $response = new Response(200);

        $new = $response->withStatus(404);
        $this->assertNotSame($response, $new);
        $this->assertEquals(404, $new->getStatusCode());
        $this->assertEquals('Not Found', $new->getReasonPhrase());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testWithStatusReturnsSameInstanceWhenStatusIsUnchanged()
    {
        $response = new Response(200);

        $new = $response->withStatus(200);
        $this->assertSame($response, $new);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testWithStatusReturnsNewInstanceWhenStatusIsUnchangedButReasonIsChanged()
    {
        $response = new Response(200);

        $new = $response->withStatus(200, 'Quite Ok');
        $this->assertNotSame($response, $new);
        $this->assertEquals(200, $new->getStatusCode());
        $this->assertEquals('Quite Ok', $new->getReasonPhrase());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testHtmlMethodReturnsHtmlResponse()
    {
        $response = Response::html('<!doctype html><body>Hello wörld!</body>');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('<!doctype html><body>Hello wörld!</body>', (string) $response->getBody());
    }

    public function testJsonMethodReturnsPrettyPrintedJsonResponse()
    {
        $response = Response::json(['text' => 'Hello wörld!']);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals("{\n    \"text\": \"Hello wörld!\"\n}\n", (string) $response->getBody());
    }

    public function testJsonMethodReturnsZeroFractionsInJsonResponse()
    {
        $response = Response::json(1.0);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals("1.0\n", (string) $response->getBody());
    }

    public function testJsonMethodReturnsJsonTextForSimpleString()
    {
        $response = Response::json('Hello world!');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertEquals("\"Hello world!\"\n", (string) $response->getBody());
    }

    public function testJsonMethodThrowsForInvalidString()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to encode given data as JSON: Malformed UTF-8 characters, possibly incorrectly encoded');
        Response::json("Hello w\xF6rld!");
    }

    public function testPlaintextMethodReturnsPlaintextResponse()
    {
        $response = Response::plaintext("Hello wörld!\n");

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals("Hello wörld!\n", (string) $response->getBody());
    }

    public function testXmlMethodReturnsXmlResponse()
    {
        $response = Response::xml('<?xml version="1.0" encoding="utf-8"?><body>Hello wörld!</body>');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/xml', $response->getHeaderLine('Content-Type'));
        $this->assertEquals('<?xml version="1.0" encoding="utf-8"?><body>Hello wörld!</body>', (string) $response->getBody());
    }

    public function testParseMessageWithMinimalOkResponse()
    {
        $response = Response::parseMessage("HTTP/1.1 200 OK\r\n");

        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals([], $response->getHeaders());
    }

    public function testParseMessageWithSimpleOkResponse()
    {
        $response = Response::parseMessage("HTTP/1.1 200 OK\r\nServer: demo\r\n");

        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithSimpleOkResponseWithCustomReasonPhrase()
    {
        $response = Response::parseMessage("HTTP/1.1 200 Mostly Okay\r\nServer: demo\r\n");

        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Mostly Okay', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithSimpleOkResponseWithEmptyReasonPhraseAppliesDefault()
    {
        $response = Response::parseMessage("HTTP/1.1 200 \r\nServer: demo\r\n");

        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithSimpleOkResponseWithoutReasonPhraseAndWhitespaceSeparatorAppliesDefault()
    {
        $response = Response::parseMessage("HTTP/1.1 200\r\nServer: demo\r\n");

        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithHttp10SimpleOkResponse()
    {
        $response = Response::parseMessage("HTTP/1.0 200 OK\r\nServer: demo\r\n");

        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithHttp10SimpleOkResponseWithLegacyNewlines()
    {
        $response = Response::parseMessage("HTTP/1.0 200 OK\nServer: demo\r\n");

        $this->assertEquals('1.0', $response->getProtocolVersion());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
        $this->assertEquals(['Server' => ['demo']], $response->getHeaders());
    }

    public function testParseMessageWithInvalidHttpProtocolVersion12Throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::parseMessage("HTTP/1.2 200 OK\r\n");
    }

    public function testParseMessageWithInvalidHttpProtocolVersion2Throws()
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::parseMessage("HTTP/2 200 OK\r\n");
    }

    public function testParseMessageWithInvalidStatusCodeUnderflowThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::parseMessage("HTTP/1.1 99 OK\r\n");
    }

    public function testParseMessageWithInvalidResponseHeaderFieldThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::parseMessage("HTTP/1.1 200 OK\r\nServer\r\n");
    }
}
