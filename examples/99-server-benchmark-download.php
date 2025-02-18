<?php

// A simple HTTP web server that can be used to benchmark requests per second and download speed
//
// $ php examples/99-server-benchmark-download.php 8080
//
// This example runs the web server on a single CPU core in order to measure the
// per core performance.
//
// $ curl http://localhost:8080/10g.bin > /dev/null
// $ wget http://localhost:8080/10g.bin -O /dev/null
// $ ab -n10 -c10 -k http://localhost:8080/1g.bin
// $ docker run -it --rm --net=host jordi/ab -n100000 -c10 -k http://localhost:8080/
// $ docker run -it --rm --net=host jordi/ab -n10 -c10 -k http://localhost:8080/1g.bin
// $ docker run -it --rm --net=host skandyla/wrk -t8 -c10 -d20 http://localhost:8080/

use Evenement\EventEmitter;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

require __DIR__ . '/../vendor/autoload.php';

/** A readable stream that can emit a lot of data */
class ChunkRepeater extends EventEmitter implements ReadableStreamInterface
{
    private $chunk;
    private $count;
    private $position = 0;
    private $paused = true;
    private $closed = false;

    public function __construct($chunk, $count)
    {
        $this->chunk = $chunk;
        $this->count = $count;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        if (!$this->paused || $this->closed) {
            return;
        }

        // keep emitting until stream is paused
        $this->paused = false;
        while ($this->position < $this->count && !$this->paused) {
            ++$this->position;
            $this->emit('data', [$this->chunk]);
        }

        // end once the last chunk has been written
        if ($this->position >= $this->count) {
            $this->emit('end');
            $this->close();
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        return;
    }

    public function isReadable()
    {
        return !$this->closed;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->count = 0;
        $this->paused = true;
        $this->emit('close');
    }

    public function getSize()
    {
        return strlen($this->chunk) * $this->count;
    }
}

$http = new React\Http\HttpServer(function (ServerRequestInterface $request) {
    switch ($request->getUri()->getPath()) {
        case '/':
            return Response::html(
                '<html><a href="1g.bin">1g.bin</a><br/><a href="10g.bin">10g.bin</a></html>'
            );
        case '/1g.bin':
            $stream = new ChunkRepeater(str_repeat('.', 1000000), 1000);
            break;
        case '/10g.bin':
            $stream = new ChunkRepeater(str_repeat('.', 1000000), 10000);
            break;
        default:
            return new Response(Response::STATUS_NOT_FOUND);
    }

    React\EventLoop\Loop::addTimer(0, [$stream, 'resume']);

    return new Response(
        Response::STATUS_OK,
        [
            'Content-Type' => 'application/octet-data',
            'Content-Length' => $stream->getSize()
        ],
        $stream
    );
});

$socket = new React\Socket\SocketServer($argv[1] ?? '0.0.0.0:0');
$http->listen($socket);

echo 'Listening on ' . str_replace('tcp:', 'http:', $socket->getAddress()) . PHP_EOL;
