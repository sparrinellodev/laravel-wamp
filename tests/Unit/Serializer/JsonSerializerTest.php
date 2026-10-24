<?php

use Hermod\LaravelWamp\Exceptions\SerializationException;
use Hermod\LaravelWamp\Serializer\JsonSerializer;

describe('JsonSerializer', function () {

    beforeEach(function () {
        $this->serializer = new JsonSerializer;
    });

    it('serializes an array to valid JSON', function () {
        $result = $this->serializer->serialize([48, 1, [], 'com.myapp.test', [3, 5]]);

        expect($result)->toBe('[48,1,[],"com.myapp.test",[3,5]]');
    });

    it('deserializes JSON to the correct array', function () {
        $result = $this->serializer->deserialize('[50,1,{},[8]]');

        expect($result)->toBe([50, 1, [], [8]]);
    });

    it('throws for invalid JSON', function () {
        $this->serializer->deserialize('non-json-valido{{{');
    })->throws(SerializationException::class);

    it('throws for JSON that is not an array', function () {
        $this->serializer->deserialize('"stringa semplice"');
    })->throws(SerializationException::class, 'is not a valid array');

    it('returns the correct subprotocol', function () {
        expect($this->serializer->subprotocol())->toBe('wamp.2.json');
    });

    it('keeps unicode unescaped', function () {
        $result = $this->serializer->serialize([1, 'ciao mondo è']);

        expect($result)->toContain('è')
            ->and($result)->not->toContain('\u');
    });

    it('round-trips data — serialize then deserialize returns the original data', function () {
        $original = [48, 42, [], 'com.myapp.procedura', [1, 2, 3], ['chiave' => 'valore']];
        $result = $this->serializer->deserialize(
            $this->serializer->serialize($original),
        );

        expect($result)->toBe($original);
    });
});
