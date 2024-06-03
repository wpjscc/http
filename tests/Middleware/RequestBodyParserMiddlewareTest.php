<?php

namespace React\Tests\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Tests\Http\TestCase;

final class RequestBodyParserMiddlewareTest extends TestCase
{
    public function testFormUrlencodedParsing()
    {
        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'hello=world'
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertSame(
            ['hello' => 'world'],
            $parsedRequest->getParsedBody()
        );
        $this->assertSame('hello=world', (string)$parsedRequest->getBody());
    }

    public function testFormUrlencodedParsingIgnoresCaseForHeadersButRespectsContentCase()
    {
        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'CONTENT-TYPE' => 'APPLICATION/X-WWW-Form-URLEncoded'
            ],
            'Hello=World'
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertSame(
            ['Hello' => 'World'],
            $parsedRequest->getParsedBody()
        );
        $this->assertSame('Hello=World', (string)$parsedRequest->getBody());
    }

    public function testFormUrlencodedParsingNestedStructure()
    {
        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'foo=bar&baz[]=cheese&bar[]=beer&bar[]=wine&market[fish]=salmon&market[meat][]=beef&market[meat][]=chicken&market[]=bazaar'
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertSame(
            [
                'foo' => 'bar',
                'baz' => [
                    'cheese',
                ],
                'bar' => [
                    'beer',
                    'wine',
                ],
                'market' => [
                    'fish' => 'salmon',
                    'meat' => [
                        'beef',
                        'chicken',
                    ],
                    0 => 'bazaar',
                ],
            ],
            $parsedRequest->getParsedBody()
        );
        $this->assertSame('foo=bar&baz[]=cheese&bar[]=beer&bar[]=wine&market[fish]=salmon&market[meat][]=beef&market[meat][]=chicken&market[]=bazaar', (string)$parsedRequest->getBody());
    }

    public function testFormUrlencodedIgnoresBodyWithExcessiveNesting()
    {
        $allowed = (int)ini_get('max_input_nesting_level');

        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'hello' . str_repeat('[]', $allowed + 1) . '=world'
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertSame(
            [],
            $parsedRequest->getParsedBody()
        );
    }

    public function testFormUrlencodedTruncatesBodyWithExcessiveLength()
    {
        $allowed = (int)ini_get('max_input_vars');

        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            str_repeat('a[]=b&', $allowed + 1)
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $body = $parsedRequest->getParsedBody();

        $this->assertCount(1, $body);
        $this->assertTrue(isset($body['a']));
        $this->assertCount($allowed, $body['a']);
    }

    public function testDoesNotParseJsonByDefault()
    {
        $middleware = new RequestBodyParserMiddleware();
        $request = new ServerRequest(
            'POST',
            'https://example.com/',
            [
                'Content-Type' => 'application/json'
            ],
            '{"hello":"world"}'
        );

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertNull($parsedRequest->getParsedBody());
        $this->assertSame('{"hello":"world"}', (string)$parsedRequest->getBody());
    }

    public function testMultipartFormDataParsing()
    {
        $middleware = new RequestBodyParserMiddleware();

        $boundary = "---------------------------12758086162038677464950549563";

        $data  = "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"users[one]\"\r\n";
        $data .= "\r\n";
        $data .= "single\r\n";
        $data .= "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"users[two]\"\r\n";
        $data .= "\r\n";
        $data .= "second\r\n";
        $data .= "--$boundary--\r\n";

        $request = new ServerRequest('POST', 'http://example.com/', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary
        ], $data, 1.1);

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertSame(
            [
                'users' => [
                    'one' => 'single',
                    'two' => 'second',
                ],
            ],
            $parsedRequest->getParsedBody()
        );
        $this->assertSame($data, (string)$parsedRequest->getBody());
    }

    public function testMultipartFormDataIgnoresFieldWithExcessiveNesting()
    {
        $allowed = (int) ini_get('max_input_nesting_level');

        $middleware = new RequestBodyParserMiddleware();

        $boundary = "---------------------------12758086162038677464950549563";

        $data  = "--$boundary\r\n";
        $data .= "Content-Disposition: form-data; name=\"hello" . str_repeat("[]", $allowed + 1) . "\"\r\n";
        $data .= "\r\n";
        $data .= "world\r\n";
        $data .= "--$boundary--\r\n";

        $request = new ServerRequest('POST', 'http://example.com/', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary
        ], $data, 1.1);

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $this->assertEmpty($parsedRequest->getParsedBody());
    }

    public function testMultipartFormDataTruncatesBodyWithExcessiveLength()
    {
        $allowed = (int) ini_get('max_input_vars');

        $middleware = new RequestBodyParserMiddleware();

        $boundary = "---------------------------12758086162038677464950549563";

        $data  = "";
        for ($i = 0; $i < $allowed + 1; ++$i) {
            $data .= "--$boundary\r\n";
            $data .= "Content-Disposition: form-data; name=\"a[]\"\r\n";
            $data .= "\r\n";
            $data .= "b\r\n";
        }
        $data .= "--$boundary--\r\n";

        $request = new ServerRequest('POST', 'http://example.com/', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary
        ], $data, 1.1);

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $body = $parsedRequest->getParsedBody();

        $this->assertCount(1, $body);
        $this->assertTrue(isset($body['a']));
        $this->assertCount($allowed, $body['a']);
    }

    public function testMultipartFormDataTruncatesExcessiveNumberOfEmptyFileUploads()
    {
        $allowed = (int) ini_get('max_input_vars');

        $middleware = new RequestBodyParserMiddleware();

        $boundary = "---------------------------12758086162038677464950549563";

        $data  = "";
        for ($i = 0; $i < $allowed + 1; ++$i) {
            $data .= "--$boundary\r\n";
            $data .= "Content-Disposition: form-data; name=\"empty[]\"; filename=\"\"\r\n";
            $data .= "\r\n";
            $data .= "\r\n";
        }
        $data .= "--$boundary--\r\n";

        $request = new ServerRequest('POST', 'http://example.com/', [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary
        ], $data, 1.1);

        /** @var ServerRequestInterface $parsedRequest */
        $parsedRequest = $middleware(
            $request,
            function (ServerRequestInterface $request) {
                return $request;
            }
        );

        $body = $parsedRequest->getUploadedFiles();
        $this->assertCount(1, $body);
        $this->assertTrue(isset($body['empty']));
        $this->assertCount($allowed, $body['empty']);
    }
}
