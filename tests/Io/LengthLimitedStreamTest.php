<?php

namespace React\Tests\Http\Io;

use React\Http\Io\LengthLimitedStream;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use React\Tests\Http\TestCase;

class LengthLimitedStreamTest extends TestCase
{
    private $input;
    private $stream;

    /**
     * @before
     */
    public function setUpInput()
    {
        $this->input = new ThroughStream();
    }

    public function testSimpleChunk()
    {
        $stream = new LengthLimitedStream($this->input, 5);
        $stream->on('data', $this->expectCallableOnceWith('hello'));
        $stream->on('end', $this->expectCallableOnce());
        $this->input->emit('data', ["hello world"]);
    }

    public function testInputStreamKeepsEmitting()
    {
        $stream = new LengthLimitedStream($this->input, 5);
        $stream->on('data', $this->expectCallableOnceWith('hello'));
        $stream->on('end', $this->expectCallableOnce());

        $this->input->emit('data', ["hello world"]);
        $this->input->emit('data', ["world"]);
        $this->input->emit('data', ["world"]);
    }

    public function testZeroLengthInContentLengthWillIgnoreEmittedDataEvents()
    {
        $stream = new LengthLimitedStream($this->input, 0);
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $this->input->emit('data', ["hello world"]);
    }

    public function testHandleError()
    {
        $stream = new LengthLimitedStream($this->input, 0);
        $stream->on('error', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $this->input->emit('error', [new \RuntimeException()]);

        $this->assertFalse($stream->isReadable());
    }

    public function testPauseStream()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->once())->method('pause');

        $stream = new LengthLimitedStream($input, 0);
        $stream->pause();
    }

    public function testResumeStream()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->once())->method('pause');

        $stream = new LengthLimitedStream($input, 0);
        $stream->pause();
        $stream->resume();
    }

    public function testPipeStream()
    {
        $stream = new LengthLimitedStream($this->input, 0);
        $dest = $this->createMock(WritableStreamInterface::class);

        $ret = $stream->pipe($dest);

        $this->assertSame($dest, $ret);
    }

    public function testHandleClose()
    {
        $stream = new LengthLimitedStream($this->input, 0);
        $stream->on('close', $this->expectCallableOnce());

        $this->input->close();
        $this->input->emit('end', []);

        $this->assertFalse($stream->isReadable());
    }

    public function testOutputStreamCanCloseInputStream()
    {
        $input = new ThroughStream();
        $input->on('close', $this->expectCallableOnce());

        $stream = new LengthLimitedStream($input, 0);
        $stream->on('close', $this->expectCallableOnce());

        $stream->close();

        $this->assertFalse($input->isReadable());
    }

    public function testHandleUnexpectedEnd()
    {
        $stream = new LengthLimitedStream($this->input, 5);

        $stream->on('data', $this->expectCallableNever());
        $stream->on('close', $this->expectCallableOnce());
        $stream->on('end', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableOnce());

        $this->input->emit('end');
    }
}
