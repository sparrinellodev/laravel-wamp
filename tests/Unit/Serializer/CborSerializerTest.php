<?php

use Hermod\LaravelWamp\Exceptions\SerializationException;
use Hermod\LaravelWamp\Serializer\CborSerializer;

describe('CborSerializer', function () {

    it('serializes an array to CBOR', function () {
        $serializer = new CborSerializer;

        $data = [1, 'hello', true, null];

        $result = $serializer->serialize($data);

        expect($result)->toBeString();
        expect($result)->not->toBeEmpty();
    });

    it('deserializes CBOR to an array', function () {
        $serializer = new CborSerializer;

        $data = [1, 'hello', true, null];

        $encoded = $serializer->serialize($data);
        $decoded = $serializer->deserialize($encoded);

        expect($decoded)->toBe($data);
    });

    it('round-trips data (serialize → deserialize)', function () {
        $serializer = new CborSerializer;

        $data = [
            123,
            'test',
            true,
            null,
            -42,
            ['nested', 1, false],
            ['key' => 'value', 'num' => 10],
        ];

        $result = $serializer->deserialize(
            $serializer->serialize($data),
        );

        expect($result)->toBe($data);
    });

    it('handles associative maps', function () {
        $serializer = new CborSerializer;

        $data = [
            'type' => 1,
            'procedure' => 'com.test',
            'args' => [1, 2, 3],
        ];

        $result = $serializer->deserialize(
            $serializer->serialize($data),
        );

        expect($result)->toBe($data);
    });

    it('throws for an unsupported type', function () {
        $serializer = new CborSerializer;

        $data = [
            'invalid' => fopen('php://memory', 'r'), // resource → unsupported
        ];

        expect(fn() => $serializer->serialize($data))
            ->toThrow(SerializationException::class);
    });

    it('throws for invalid CBOR', function () {
        $serializer = new CborSerializer;

        $invalid = 'not-valid-cbor';

        expect(fn() => $serializer->deserialize($invalid))
            ->toThrow(SerializationException::class);
    });

    it('returns the correct subprotocol', function () {
        $serializer = new CborSerializer;

        expect($serializer->subprotocol())
            ->toBe('wamp.2.cbor');
    });

    it('handles negative and positive integers', function () {
        $serializer = new CborSerializer;

        $data = [0, 1, -1, 42, -999];

        $result = $serializer->deserialize(
            $serializer->serialize($data),
        );

        expect($result)->toBe($data);
    });

    it('handles 64-bit integers (WAMP session IDs)', function () {
        $sessionId = 3084194912322238; // Typical Crossbar.io session ID
        $data      = [2, $sessionId, []];

        $encoded = (new CborSerializer())->serialize($data);
        $decoded = (new CborSerializer())->deserialize($encoded);

        expect($decoded[1])->toBe($sessionId);
    });
});
