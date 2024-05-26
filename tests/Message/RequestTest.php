<?php

namespace React\Tests\Http\Message;

use Psr\Http\Message\StreamInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\Request;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Tests\Http\TestCase;

class RequestTest extends TestCase
{
    public function testConstructWithStringRequestBodyReturnsStringBodyWithAutomaticSize()
    {
        $request = new Request(
            'GET',
            'http://localhost',
            [],
            'foo'
        );

        $body = $request->getBody();
        $this->assertSame(3, $body->getSize());
        $this->assertEquals('foo', (string) $body);
    }

    public function testConstructWithStreamingRequestBodyReturnsBodyWhichImplementsReadableStreamInterfaceWithUnknownSize()
    {
        $request = new Request(
            'GET',
            'http://localhost',
            [],
            new ThroughStream()
        );

        $body = $request->getBody();
        $this->assertInstanceOf(StreamInterface::class, $body);
        $this->assertInstanceOf(ReadableStreamInterface::class, $body);
        $this->assertNull($body->getSize());
    }

    public function testConstructWithHttpBodyStreamReturnsBodyAsIs()
    {
        $request = new Request(
            'GET',
            'http://localhost',
            [],
            $body = new HttpBodyStream(new ThroughStream(), 100)
        );

        $this->assertSame($body, $request->getBody());
    }

    public function testConstructWithNullBodyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid request body given');
        new Request(
            'GET',
            'http://localhost',
            [],
            null
        );
    }
}
