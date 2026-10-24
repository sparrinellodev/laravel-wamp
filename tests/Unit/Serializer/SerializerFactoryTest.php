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

    it('creates a JsonSerializer', function () {
        $serializer = $this->factory->make('json');

        expect($serializer)->toBeInstanceOf(JsonSerializer::class);
    });

    it('throws for an unknown driver', function () {
        $this->factory->make('driver-inesistente');
    })->throws(SerializationException::class, 'is not supported');

    it('throws for a missing class', function () {
        $factory = new SerializerFactory(['fake' => 'Hermod\\Serializer\\FakeSerializer']);
        $factory->make('fake');
    })->throws(SerializationException::class, 'not found');
});
