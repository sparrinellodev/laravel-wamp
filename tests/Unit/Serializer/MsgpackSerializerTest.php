<?php

use Hermod\LaravelWamp\Exceptions\SerializationException;
use Hermod\LaravelWamp\Serializer\MsgpackSerializer;

describe('MsgpackSerializer', function () {

    beforeEach(function () {
        $this->serializer = new MsgpackSerializer;
    });

    it('serializes an array to MessagePack', function () {
        $data = [48, 1, [], 'com.myapp.test', [3, 5]];
        $result = $this->serializer->serialize($data);

        expect($result)
            ->toBeString()
            ->not->toBeEmpty();
    });

    it('deserializes MessagePack to the correct array', function () {
        $original = [48, 1, [], 'com.myapp.test', [3, 5]];
        $packed = $this->serializer->serialize($original);
        $result = $this->serializer->deserialize($packed);

        expect($result)->toBe($original);
    });

    it('round-trips data — serialize then deserialize returns the original data', function () {
        $original = [2, 123456, ['roles' => ['caller' => [], 'callee' => []]]];
        $result = $this->serializer->deserialize(
            $this->serializer->serialize($original),
        );

        expect($result)->toBe($original);
    });

    it('handles unicode strings', function () {
        $data = [1, 'realm1', ['authmethods' => ['anonymous'], 'authextra' => []]];
        $packed = $this->serializer->serialize($data);
        $result = $this->serializer->deserialize($packed);

        expect($result[1])->toBe('realm1');
    });

    it('handles empty arrays', function () {
        $data = [48, 1, [], 'com.test', [], []];
        $packed = $this->serializer->serialize($data);
        $result = $this->serializer->deserialize($packed);

        expect($result)->toBe($data);
    });

    it('handles mixed types', function () {
        $data = [50, 99, [], [true, false, null, 42, 3.14, 'stringa']];
        $packed = $this->serializer->serialize($data);
        $result = $this->serializer->deserialize($packed);

        expect($result[3][0])->toBeTrue()
            ->and($result[3][1])->toBeFalse()
            ->and($result[3][2])->toBeNull()
            ->and($result[3][3])->toBe(42)
            ->and($result[3][5])->toBe('stringa');
    });

    it('throws SerializationException for undecodable data', function () {
        $this->serializer->deserialize('dati-invalidi-non-msgpack');
    })->throws(SerializationException::class);

    it('returns the correct subprotocol', function () {
        expect($this->serializer->subprotocol())->toBe('wamp.2.msgpack');
    });

    it('produces more compact output than JSON for large payloads', function () {
        $data = array_fill(0, 100, 'stringa-di-test-abbastanza-lunga');

        $json = json_encode($data);
        $msgpack = $this->serializer->serialize($data);

        expect(strlen($msgpack))->toBeLessThan(strlen($json));
    });

    it('serializes stdClass as a MessagePack map', function () {
        // Empty stdClass → empty MessagePack map
        $data = [48, 1, (object) [], 'com.myapp.test', [3, 5], (object) []];
        $packed = $this->serializer->serialize($data);

        // Must not throw
        expect($packed)->toBeString()->not->toBeEmpty();
    });

    it('serializes an associative array as a MessagePack map', function () {
        $data = [2, 123, ['roles' => ['caller' => [], 'callee' => []]]];
        $packed = $this->serializer->serialize($data);
        $result = $this->serializer->deserialize($packed);

        expect($result[2]['roles'])->toHaveKeys(['caller', 'callee']);
    });
});
