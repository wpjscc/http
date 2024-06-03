<?php

namespace React\Tests\Http\Io;

use Psr\Http\Message\RequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Client\Client as HttpClient;
use React\Http\Io\ClientConnectionManager;
use React\Http\Io\ClientRequestStream;
use React\Http\Io\EmptyBodyStream;
use React\Http\Io\ReadableBodyStream;
use React\Http\Io\Sender;
use React\Http\Message\Request;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Stream\ThroughStream;
use React\Tests\Http\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class SenderTest extends TestCase
{
    /** @var LoopInterface */
    private $loop;

    /**
     * @before
     */
    public function setUpLoop()
    {
        $this->loop = $this->createMock(LoopInterface::class);
    }

    public function testCreateFromLoop()
    {
        $sender = Sender::createFromLoop($this->loop, null);

        $this->assertInstanceOf(Sender::class, $sender);
    }

    public function testSenderRejectsInvalidUri()
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $sender = new Sender(new HttpClient(new ClientConnectionManager($connector, $this->loop)));

        $request = new Request('GET', 'www.google.com');

        $promise = $sender->send($request);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    public function testSenderConnectorRejection()
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->willReturn(reject(new \RuntimeException('Rejected')));

        $sender = new Sender(new HttpClient(new ClientConnectionManager($connector, $this->loop)));

        $request = new Request('GET', 'http://www.google.com/');

        $promise = $sender->send($request);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testSendPostWillAutomaticallySendContentLengthHeader()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Content-Length') === '5';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('POST', 'http://www.google.com/', [], 'hello');
        $sender->send($request);
    }

    public function testSendPostWillAutomaticallySendContentLengthZeroHeaderForEmptyRequestBody()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Content-Length') === '0';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('POST', 'http://www.google.com/', [], '');
        $sender->send($request);
    }

    public function testSendPostStreamWillAutomaticallySendTransferEncodingChunked()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('write')->with("");

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Transfer-Encoding') === 'chunked';
        }))->willReturn($outgoing);

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $sender->send($request);
    }

    public function testSendPostStreamWillAutomaticallyPipeChunkEncodeBodyForWriteAndRespectRequestThrottling()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('isWritable')->willReturn(true);
        $outgoing->expects($this->exactly(2))->method('write')->withConsecutive([""], ["5\r\nhello\r\n"])->willReturn(false);

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->willReturn($outgoing);

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $sender->send($request);

        $ret = $stream->write('hello');
        $this->assertFalse($ret);
    }

    public function testSendPostStreamWillAutomaticallyPipeChunkEncodeBodyForEnd()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('isWritable')->willReturn(true);
        $outgoing->expects($this->exactly(2))->method('write')->withConsecutive([""], ["0\r\n\r\n"])->willReturn(false);
        $outgoing->expects($this->once())->method('end')->with(null);

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->willReturn($outgoing);

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $sender->send($request);

        $stream->end();
    }

    public function testSendPostStreamWillRejectWhenRequestBodyEmitsErrorEvent()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('isWritable')->willReturn(true);
        $outgoing->expects($this->once())->method('write')->with("")->willReturn(false);
        $outgoing->expects($this->never())->method('end');
        $outgoing->expects($this->once())->method('close');

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->willReturn($outgoing);

        $sender = new Sender($client);

        $expected = new \RuntimeException();
        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $promise = $sender->send($request);

        $stream->emit('error', [$expected]);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Request failed because request body reported an error', $exception->getMessage());
        $this->assertSame($expected, $exception->getPrevious());
    }

    public function testSendPostStreamWillRejectWhenRequestBodyClosesWithoutEnd()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('isWritable')->willReturn(true);
        $outgoing->expects($this->once())->method('write')->with("")->willReturn(false);
        $outgoing->expects($this->never())->method('end');
        $outgoing->expects($this->once())->method('close');

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->willReturn($outgoing);

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $promise = $sender->send($request);

        $stream->close();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Request failed because request body closed unexpectedly', $exception->getMessage());
    }

    public function testSendPostStreamWillNotRejectWhenRequestBodyClosesAfterEnd()
    {
        $outgoing = $this->createMock(ClientRequestStream::class);
        $outgoing->expects($this->once())->method('isWritable')->willReturn(true);
        $outgoing->expects($this->exactly(2))->method('write')->withConsecutive([""], ["0\r\n\r\n"])->willReturn(false);
        $outgoing->expects($this->once())->method('end');
        $outgoing->expects($this->never())->method('close');

        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->willReturn($outgoing);

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', [], new ReadableBodyStream($stream));
        $promise = $sender->send($request);

        $stream->end();
        $stream->close();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertNull($exception);
    }

    public function testSendPostStreamWithExplicitContentLengthWillSendHeaderAsIs()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Content-Length') === '100';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $stream = new ThroughStream();
        $request = new Request('POST', 'http://www.google.com/', ['Content-Length' => '100'], new ReadableBodyStream($stream));
        $sender->send($request);
    }

    public function testSendGetWillNotPassContentLengthHeaderForEmptyRequestBody()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return !$request->hasHeader('Content-Length');
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('GET', 'http://www.google.com/');
        $sender->send($request);
    }

    public function testSendGetWithEmptyBodyStreamWillNotPassContentLengthOrTransferEncodingHeader()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return !$request->hasHeader('Content-Length') && !$request->hasHeader('Transfer-Encoding');
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $body = new EmptyBodyStream();
        $request = new Request('GET', 'http://www.google.com/', [], $body);

        $sender->send($request);
    }

    public function testSendCustomMethodWillNotPassContentLengthHeaderForEmptyRequestBody()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return !$request->hasHeader('Content-Length');
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('CUSTOM', 'http://www.google.com/');
        $sender->send($request);
    }

    public function testSendCustomMethodWithExplicitContentLengthZeroWillBePassedAsIs()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Content-Length') === '0';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('CUSTOM', 'http://www.google.com/', ['Content-Length' => '0']);
        $sender->send($request);
    }

    /** @test */
    public function getRequestWithUserAndPassShouldSendAGetRequestWithBasicAuthorizationHeader()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Authorization') === 'Basic am9objpkdW1teQ==';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('GET', 'http://john:dummy@www.example.com');
        $sender->send($request);
    }

    /** @test */
    public function getRequestWithUserAndPassShouldSendAGetRequestWithGivenAuthorizationHeaderBasicAuthorizationHeader()
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with($this->callback(function (RequestInterface $request) {
            return $request->getHeaderLine('Authorization') === 'bearer abc123';
        }))->willReturn($this->createMock(ClientRequestStream::class));

        $sender = new Sender($client);

        $request = new Request('GET', 'http://john:dummy@www.example.com', ['Authorization' => 'bearer abc123']);
        $sender->send($request);
    }

    public function testCancelRequestWillCancelConnector()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->willReturn($promise);

        $sender = new Sender(new HttpClient(new ClientConnectionManager($connector, $this->loop)));

        $request = new Request('GET', 'http://www.google.com/');

        $promise = $sender->send($request);
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testCancelRequestWillCloseConnection()
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->willReturn(resolve($connection));

        $sender = new Sender(new HttpClient(new ClientConnectionManager($connector, $this->loop)));

        $request = new Request('GET', 'http://www.google.com/');

        $promise = $sender->send($request);
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
