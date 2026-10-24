<?php

use Hermod\LaravelWamp\Exceptions\SerializationException;
use Hermod\LaravelWamp\Serializer\CborSerializer;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Serializer\MsgpackSerializer;
use Hermod\LaravelWamp\Serializer\SerializerFactory;

describe('SerializerFactory', function () {

    beforeEach(function () {
        $this->factory = new SerializerFactory([
            'json' => JsonSerializer::class,
            'msgpack' => MsgpackSerializer::class,
            'cbor' => CborSerializer::class,
        ]);
    });

    it('crea un JsonSerializer correttamente', function () {
        $serializer = $this->factory->make('json');

        expect($serializer)->toBeInstanceOf(JsonSerializer::class);
    });

    it('lancia eccezione per driver sconosciuto', function () {
        $this->factory->make('driver-inesistente');
    })->throws(SerializationException::class, 'is not supported');

    it('lancia eccezione per classe inesistente', function () {
        $factory = new SerializerFactory(['fake' => 'Hermod\\Serializer\\FakeSerializer']);
        $factory->make('fake');
    })->throws(SerializationException::class, 'not found');
});
