<?php

use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Transport\RawSocket\RawSocketTransport;
use Hermod\LaravelWamp\Transport\RawSocketTransportFactory;
use Hermod\LaravelWamp\Transport\TransportFactory;
use Hermod\LaravelWamp\Transport\WebSocketTransport;
use Hermod\LaravelWamp\Transport\WebSocketTransportFactory;

describe('TransportFactory', function () {

    beforeEach(function () {
        $this->factory = new TransportFactory(
            websocketFactory: new WebSocketTransportFactory,
            rawSocketFactory: new RawSocketTransportFactory,
        );

        $this->serializer = new JsonSerializer;
    });

    it('crea un WebSocketTransport per tipo websocket', function () {
        $transport = $this->factory->make(
            type: 'websocket',
            url: 'ws://localhost:8080/ws',
            serializer: $this->serializer,
        );

        expect($transport)->toBeInstanceOf(WebSocketTransport::class);
    });

    it('crea un RawSocketTransport per tipo rawsocket TCP', function () {
        $transport = $this->factory->make(
            type: 'rawsocket',
            url: 'tcp://localhost:8081',
            serializer: $this->serializer,
        );

        expect($transport)->toBeInstanceOf(RawSocketTransport::class);
    });

    it('crea un RawSocketTransport per tipo rawsocket Unix', function () {
        $transport = $this->factory->make(
            type: 'rawsocket',
            url: 'unix:///var/run/crossbar/router.sock',
            serializer: $this->serializer,
        );

        expect($transport)->toBeInstanceOf(RawSocketTransport::class);
    });

    it('lancia TransportException per tipo sconosciuto', function () {
        $this->factory->make(
            type: 'sconosciuto',
            url: 'tcp://localhost:8081',
            serializer: $this->serializer,
        );
    })->throws(TransportException::class, 'Unsupported transport type');
});
