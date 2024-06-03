<?php

namespace React\Tests\Http\Io;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Io\ClientConnectionManager;
use React\Http\Message\Uri;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Tests\Http\TestCase;
use function React\Promise\resolve;

class ClientConnectionManagerTest extends TestCase
{
    public function testConnectWithHttpsUriShouldConnectToTlsWithDefaultPort()
    {
        $promise = new Promise(function () { });
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tls://reactphp.org:443')->willReturn($promise);

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $ret = $connectionManager->connect(new Uri('https://reactphp.org/'));

        assert($ret instanceof PromiseInterface);
        $this->assertSame($promise, $ret);
    }

    public function testConnectWithHttpUriShouldConnectToTcpWithDefaultPort()
    {
        $promise = new Promise(function () { });
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('reactphp.org:80')->willReturn($promise);

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $ret = $connectionManager->connect(new Uri('http://reactphp.org/'));
        $this->assertSame($promise, $ret);
    }

    public function testConnectWithExplicitPortShouldConnectWithGivenPort()
    {
        $promise = new Promise(function () { });
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('reactphp.org:8080')->willReturn($promise);

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $ret = $connectionManager->connect(new Uri('http://reactphp.org:8080/'));
        $this->assertSame($promise, $ret);
    }

    public function testConnectWithInvalidSchemeShouldRejectWithException()
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $promise = $connectionManager->connect(new Uri('ftp://reactphp.org/'));

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid request URL given', $exception->getMessage());
    }

    public function testConnectWithoutSchemeShouldRejectWithException()
    {
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $promise = $connectionManager->connect(new Uri('reactphp.org'));

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid request URL given', $exception->getMessage());
    }

    public function testConnectReusesIdleConnectionFromPreviousKeepAliveCallWithoutUsingConnectorAndWillAddAndRemoveStreamEventsAndAddAndCancelIdleTimer()
    {
        $connectionToReuse = $this->createMock(ConnectionInterface::class);

        $streamHandler = null;
        $connectionToReuse->expects($this->exactly(3))->method('on')->withConsecutive(
            [
                'close',
                $this->callback(function ($cb) use (&$streamHandler) {
                    $streamHandler = $cb;
                    return true;
                })
            ],
            [
                'data',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ],
            [
                'error',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ]
        );

        $connectionToReuse->expects($this->exactly(3))->method('removeListener')->withConsecutive(
            [
                'close',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ],
            [
                'data',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ],
            [
                'error',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ]
        );

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $connectionToReuse);

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/'));
        assert($promise instanceof PromiseInterface);

        $connection = null;
        $promise->then(function ($value) use (&$connection) {
            $connection = $value;
        });

        $this->assertSame($connectionToReuse, $connection);
    }

    public function testConnectReusesIdleConnectionFromPreviousKeepAliveCallWithoutUsingConnectorAlsoWhenUriPathAndQueryAndFragmentIsDifferent()
    {
        $connectionToReuse = $this->createMock(ConnectionInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/http?foo#bar'), $connectionToReuse);

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/http/'));
        assert($promise instanceof PromiseInterface);

        $connection = null;
        $promise->then(function ($value) use (&$connection) {
            $connection = $value;
        });

        $this->assertSame($connectionToReuse, $connection);
    }

    public function testConnectUsesConnectorWithSameUriAndReturnsPromiseForNewConnectionFromConnectorWhenPreviousKeepAliveCallUsedDifferentUri()
    {
        $connectionToReuse = $this->createMock(ConnectionInterface::class);

        $promise = new Promise(function () { });
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tls://reactphp.org:443')->willReturn($promise);

        $loop = $this->createMock(LoopInterface::class);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('http://reactphp.org/'), $connectionToReuse);

        $ret = $connectionManager->connect(new Uri('https://reactphp.org/'));

        assert($ret instanceof PromiseInterface);
        $this->assertSame($promise, $ret);
    }

    public function testConnectUsesConnectorForNewConnectionWhenPreviousConnectReusedIdleConnectionFromPreviousKeepAliveCall()
    {
        $firstConnection = $this->createMock(ConnectionInterface::class);
        $secondConnection = $this->createMock(ConnectionInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tls://reactphp.org:443')->willReturn(resolve($secondConnection));

        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $firstConnection);

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/'));
        assert($promise instanceof PromiseInterface);

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/'));
        assert($promise instanceof PromiseInterface);

        $connection = null;
        $promise->then(function ($value) use (&$connection) {
            $connection = $value;
        });

        $this->assertSame($secondConnection, $connection);
    }

    public function testKeepAliveAddsTimerAndDoesNotCloseConnectionImmediately()
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('close');

        $connector = $this->createMock(ConnectorInterface::class);

        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $connection);
    }

    public function testKeepAliveClosesConnectionAfterIdleTimeout()
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $connector = $this->createMock(ConnectorInterface::class);

        $timerCallback = null;
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $connection);

        // manually invoker timer function to emulate time has passed
        $this->assertNotNull($timerCallback);
        call_user_func($timerCallback); // $timerCallback() (PHP 5.4+)
    }

    public function testConnectUsesConnectorForNewConnectionWhenIdleConnectionFromPreviousKeepAliveCallHasAlreadyTimedOut()
    {
        $firstConnection = $this->createMock(ConnectionInterface::class);
        $firstConnection->expects($this->once())->method('close');

        $secondConnection = $this->createMock(ConnectionInterface::class);
        $secondConnection->expects($this->never())->method('close');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tls://reactphp.org:443')->willReturn(resolve($secondConnection));

        $timerCallback = null;
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $firstConnection);

        // manually invoker timer function to emulate time has passed
        $this->assertNotNull($timerCallback);
        call_user_func($timerCallback); // $timerCallback() (PHP 5.4+)

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/'));
        assert($promise instanceof PromiseInterface);

        $connection = null;
        $promise->then(function ($value) use (&$connection) {
            $connection = $value;
        });

        $this->assertSame($secondConnection, $connection);
    }

    public function testConnectUsesConnectorForNewConnectionWhenIdleConnectionFromPreviousKeepAliveCallHasAlreadyFiredUnexpectedStreamEventBeforeIdleTimeoutThatClosesConnection()
    {
        $firstConnection = $this->createMock(ConnectionInterface::class);
        $firstConnection->expects($this->once())->method('close');

        $streamHandler = null;
        $firstConnection->expects($this->exactly(3))->method('on')->withConsecutive(
            [
                'close',
                $this->callback(function ($cb) use (&$streamHandler) {
                    $streamHandler = $cb;
                    return true;
                })
            ],
            [
                'data',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ],
            [
                'error',
                $this->callback(function ($cb) use (&$streamHandler) {
                    assert($streamHandler instanceof \Closure);
                    return $cb === $streamHandler;
                })
            ]
        );

        $secondConnection = $this->createMock(ConnectionInterface::class);
        $secondConnection->expects($this->never())->method('close');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tls://reactphp.org:443')->willReturn(resolve($secondConnection));

        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connectionManager = new ClientConnectionManager($connector, $loop);

        $connectionManager->keepAlive(new Uri('https://reactphp.org/'), $firstConnection);

        // manually invoke connection close to emulate server closing idle connection before idle timeout
        $this->assertNotNull($streamHandler);
        call_user_func($streamHandler); // $streamHandler() (PHP 5.4+)

        $promise = $connectionManager->connect(new Uri('https://reactphp.org/'));
        assert($promise instanceof PromiseInterface);

        $connection = null;
        $promise->then(function ($value) use (&$connection) {
            $connection = $value;
        });

        $this->assertSame($secondConnection, $connection);
    }
}
