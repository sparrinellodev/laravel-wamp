<?php

use Hermod\LaravelWamp\Exceptions\SerializationException;
use Hermod\LaravelWamp\Serializer\JsonSerializer;

describe('JsonSerializer', function () {

    beforeEach(function () {
        $this->serializer = new JsonSerializer;
    });

    it('serializza un array in JSON corretto', function () {
        $result = $this->serializer->serialize([48, 1, [], 'com.myapp.test', [3, 5]]);

        expect($result)->toBe('[48,1,[],"com.myapp.test",[3,5]]');
    });

    it('deserializza JSON in array corretto', function () {
        $result = $this->serializer->deserialize('[50,1,{},[8]]');

        expect($result)->toBe([50, 1, [], [8]]);
    });

    it('lancia eccezione per JSON non valido', function () {
        $this->serializer->deserialize('non-json-valido{{{');
    })->throws(SerializationException::class);

    it('lancia eccezione per JSON non array', function () {
        $this->serializer->deserialize('"stringa semplice"');
    })->throws(SerializationException::class, 'is not a valid array');

    it('restituisce il subprotocol corretto', function () {
        expect($this->serializer->subprotocol())->toBe('wamp.2.json');
    });

    it('gestisce unicode senza escape', function () {
        $result = $this->serializer->serialize([1, 'ciao mondo è']);

        expect($result)->toContain('è')
            ->and($result)->not->toContain('\u');
    });

    it('è simmetrico — serialize poi deserialize restituisce i dati originali', function () {
        $original = [48, 42, [], 'com.myapp.procedura', [1, 2, 3], ['chiave' => 'valore']];
        $result = $this->serializer->deserialize(
            $this->serializer->serialize($original),
        );

        expect($result)->toBe($original);
    });
});
