<?php

namespace React\Tests\Http\Io;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use React\Http\Io\AbstractRequest;
use React\Http\Message\Uri;
use React\Tests\Http\TestCase;

class RequestMock extends AbstractRequest
{
    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array<string,string|string[]> $headers
     * @param StreamInterface $body
     * @param string $protocolVersion
     */
    public function __construct(
        $method,
        $uri,
        array $headers,
        StreamInterface $body,
        $protocolVersion
    ) {
        parent::__construct($method, $uri, $headers, $body, $protocolVersion);
    }
}

class AbstractRequestTest extends TestCase
{
    public function testCtorWithInvalidUriThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestMock(
            'GET',
            null,
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );
    }

    public function testGetHeadersReturnsHostHeaderFromUri()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWithCustomHttpPort()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com:8080/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com:8080']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWithCustomPortHttpOnHttpsPort()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com:443/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com:443']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWithCustomPortHttpsOnHttpPort()
    {
        $request = new RequestMock(
            'GET',
            'https://example.com:80/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com:80']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWithoutDefaultHttpPort()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com:80/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWithoutDefaultHttpsPort()
    {
        $request = new RequestMock(
            'GET',
            'https://example.com:443/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriBeforeOtherHeadersExplicitlyGiven()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [
                'User-Agent' => 'demo'
            ],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com'], 'User-Agent' => ['demo']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromHeadersExplicitlyGiven()
    {
        $request = new RequestMock(
            'GET',
            'http://localhost/',
            [
                'Host' => 'example.com:8080'
            ],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com:8080']], $request->getHeaders());
    }

    public function testGetHeadersReturnsHostHeaderFromUriWhenHeadersExplicitlyGivenContainEmptyHostArray()
    {
        $request = new RequestMock(
            'GET',
            'https://example.com/',
            [
                'Host' => []
            ],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals(['Host' => ['example.com']], $request->getHeaders());
    }

    public function testGetRequestTargetReturnsPathAndQueryFromUri()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/demo?name=Alice',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals('/demo?name=Alice', $request->getRequestTarget());
    }

    public function testGetRequestTargetReturnsSlashOnlyIfUriHasNoPathOrQuery()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertEquals('/', $request->getRequestTarget());
    }

    public function testGetRequestTargetReturnsRequestTargetInAbsoluteFormIfGivenExplicitly()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/demo?name=Alice',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );
        $request = $request->withRequestTarget('http://example.com/demo?name=Alice');

        $this->assertEquals('http://example.com/demo?name=Alice', $request->getRequestTarget());
    }

    public function testWithRequestTargetReturnsNewInstanceWhenRequestTargetIsChanged()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $new = $request->withRequestTarget('http://example.com/');
        $this->assertNotSame($request, $new);
        $this->assertEquals('http://example.com/', $new->getRequestTarget());
        $this->assertEquals('/', $request->getRequestTarget());
    }

    public function testWithRequestTargetReturnsSameInstanceWhenRequestTargetIsUnchanged()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );
        $request = $request->withRequestTarget('/');

        $new = $request->withRequestTarget('/');
        $this->assertSame($request, $new);
        $this->assertEquals('/', $request->getRequestTarget());
    }

    public function testWithMethodReturnsNewInstanceWhenMethodIsChanged()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $new = $request->withMethod('POST');
        $this->assertNotSame($request, $new);
        $this->assertEquals('POST', $new->getMethod());
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testWithMethodReturnsSameInstanceWhenMethodIsUnchanged()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $new = $request->withMethod('GET');
        $this->assertSame($request, $new);
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testGetUriReturnsUriInstanceGivenToCtor()
    {
        $uri = $this->createMock(UriInterface::class);

        $request = new RequestMock(
            'GET',
            $uri,
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $this->assertSame($uri, $request->getUri());
    }

    public function testGetUriReturnsUriInstanceForUriStringGivenToCtor()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = $request->getUri();
        $this->assertInstanceOf(UriInterface::class, $uri);
        $this->assertEquals('http://example.com/', (string) $uri);
    }

    public function testWithUriReturnsNewInstanceWhenUriIsChanged()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = $this->createMock(UriInterface::class);
        $new = $request->withUri($uri);

        $this->assertNotSame($request, $new);
        $this->assertEquals($uri, $new->getUri());
        $this->assertEquals('http://example.com/', (string) $request->getUri());
    }

    public function testWithUriReturnsSameInstanceWhenUriIsUnchanged()
    {
        $uri = $this->createMock(UriInterface::class);

        $request = new RequestMock(
            'GET',
            $uri,
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $new = $request->withUri($uri);
        $this->assertSame($request, $new);
        $this->assertEquals($uri, $request->getUri());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderChangedIfUriContainsHost()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = new Uri('http://localhost/');
        $new = $request->withUri($uri);

        $this->assertNotSame($request, $new);
        $this->assertEquals('http://localhost/', (string) $new->getUri());
        $this->assertEquals(['Host' => ['localhost']], $new->getHeaders());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderChangedIfUriContainsHostWithCustomPort()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = new Uri('http://localhost:8080/');
        $new = $request->withUri($uri);

        $this->assertNotSame($request, $new);
        $this->assertEquals('http://localhost:8080/', (string) $new->getUri());
        $this->assertEquals(['Host' => ['localhost:8080']], $new->getHeaders());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderAddedAsFirstHeaderBeforeOthersIfUriContainsHost()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [
                'User-Agent' => 'test'
            ],
            $this->createMock(StreamInterface::class),
            '1.1'
        );
        $request = $request->withoutHeader('Host');

        $uri = new Uri('http://localhost/');
        $new = $request->withUri($uri);

        $this->assertNotSame($request, $new);
        $this->assertEquals('http://localhost/', (string) $new->getUri());
        $this->assertEquals(['Host' => ['localhost'], 'User-Agent' => ['test']], $new->getHeaders());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderUnchangedIfUriContainsNoHost()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = new Uri('/path');
        $new = $request->withUri($uri);

        $this->assertNotSame($request, $new);
        $this->assertEquals('/path', (string) $new->getUri());
        $this->assertEquals(['Host' => ['example.com']], $new->getHeaders());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderUnchangedIfPreserveHostIsTrue()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [],
            $this->createMock(StreamInterface::class),
            '1.1'
        );

        $uri = new Uri('http://localhost/');
        $new = $request->withUri($uri, true);

        $this->assertNotSame($request, $new);
        $this->assertEquals('http://localhost/', (string) $new->getUri());
        $this->assertEquals(['Host' => ['example.com']], $new->getHeaders());
    }

    public function testWithUriReturnsNewInstanceWithHostHeaderAddedAsFirstHeaderNoMatterIfPreserveHostIsTrue()
    {
        $request = new RequestMock(
            'GET',
            'http://example.com/',
            [
                'User-Agent' => 'test'
            ],
            $this->createMock(StreamInterface::class),
            '1.1'
        );
        $request = $request->withoutHeader('Host');

        $uri = new Uri('http://example.com/');
        $new = $request->withUri($uri, true);

        $this->assertNotSame($request, $new);
        $this->assertEquals('http://example.com/', (string) $new->getUri());
        $this->assertEquals(['Host' => ['example.com'], 'User-Agent' => ['test']], $new->getHeaders());
    }
}
