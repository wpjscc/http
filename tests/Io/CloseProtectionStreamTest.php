<?php

namespace React\Tests\Http\Io;

use React\Http\Io\CloseProtectionStream;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use React\Tests\Http\TestCase;

class CloseProtectionStreamTest extends TestCase
{
    public function testCloseDoesNotCloseTheInputStream()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->never())->method('pause');
        $input->expects($this->never())->method('resume');
        $input->expects($this->never())->method('close');

        $protection = new CloseProtectionStream($input);
        $protection->close();
    }

    public function testErrorWontCloseStream()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $protection->on('error', $this->expectCallableOnce());
        $protection->on('close', $this->expectCallableNever());

        $input->emit('error', [new \RuntimeException()]);

        $this->assertTrue($protection->isReadable());
        $this->assertTrue($input->isReadable());
    }

    public function testResumeStreamWillResumeInputStream()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->once())->method('pause');
        $input->expects($this->once())->method('resume');

        $protection = new CloseProtectionStream($input);
        $protection->pause();
        $protection->resume();
    }

    public function testCloseResumesInputStreamIfItWasPreviouslyPaused()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->once())->method('pause');
        $input->expects($this->once())->method('resume');

        $protection = new CloseProtectionStream($input);
        $protection->pause();
        $protection->close();
    }

    public function testInputStreamIsNotReadableAfterClose()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $protection->on('close', $this->expectCallableOnce());

        $input->close();

        $this->assertFalse($protection->isReadable());
        $this->assertFalse($input->isReadable());
    }

    public function testPipeStream()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $dest = $this->createMock(WritableStreamInterface::class);

        $ret = $protection->pipe($dest);

        $this->assertSame($dest, $ret);
    }

    public function testStopEmittingDataAfterClose()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $protection->on('data', $this->expectCallableNever());

        $protection->on('close', $this->expectCallableOnce());

        $protection->close();

        $input->emit('data', ['hello']);

        $this->assertFalse($protection->isReadable());
        $this->assertTrue($input->isReadable());
    }

    public function testErrorIsNeverCalledAfterClose()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $protection->on('data', $this->expectCallableNever());
        $protection->on('error', $this->expectCallableNever());
        $protection->on('close', $this->expectCallableOnce());

        $protection->close();

        $input->emit('error', [new \Exception()]);

        $this->assertFalse($protection->isReadable());
        $this->assertTrue($input->isReadable());
    }

    public function testEndWontBeEmittedAfterClose()
    {
        $input = new ThroughStream();

        $protection = new CloseProtectionStream($input);
        $protection->on('data', $this->expectCallableNever());
        $protection->on('close', $this->expectCallableOnce());

        $protection->close();

        $input->emit('end', []);

        $this->assertFalse($protection->isReadable());
        $this->assertTrue($input->isReadable());
    }

    public function testPauseAfterCloseHasNoEffect()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->never())->method('pause');
        $input->expects($this->never())->method('resume');

        $protection = new CloseProtectionStream($input);
        $protection->on('data', $this->expectCallableNever());
        $protection->on('close', $this->expectCallableOnce());

        $protection->close();
        $protection->pause();
    }

    public function testResumeAfterCloseHasNoEffect()
    {
        $input = $this->createMock(ReadableStreamInterface::class);
        $input->expects($this->never())->method('pause');
        $input->expects($this->never())->method('resume');

        $protection = new CloseProtectionStream($input);
        $protection->on('data', $this->expectCallableNever());
        $protection->on('close', $this->expectCallableOnce());

        $protection->close();
        $protection->resume();
    }
}
