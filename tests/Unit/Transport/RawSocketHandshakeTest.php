<?php

use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Transport\RawSocket\RawSocketHandshake;

describe('RawSocketHandshake', function () {

    // -------------------------------------------------------------------------
    // build()
    // -------------------------------------------------------------------------

    describe('build()', function () {

        it('builds a valid 4-byte JSON handshake', function () {
            $result = RawSocketHandshake::build('wamp.2.json');

            expect(strlen($result))->toBe(4);

            $bytes = unpack('C4', $result);
            expect($bytes[1])->toBe(0x7F); // magic byte
        });

        it('uses the correct serializer code for JSON', function () {
            $result = RawSocketHandshake::build('wamp.2.json');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(1); // JSON = 1
        });

        it('uses the correct serializer code for MessagePack', function () {
            $result = RawSocketHandshake::build('wamp.2.msgpack');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(2); // MessagePack = 2
        });

        it('uses the correct serializer code for CBOR', function () {
            $result = RawSocketHandshake::build('wamp.2.cbor');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(3); // CBOR = 3
        });

        it('sets the reserved bytes to zero', function () {
            $result = RawSocketHandshake::build('wamp.2.json');
            $bytes = unpack('C4', $result);

            expect($bytes[3])->toBe(0x00)
                ->and($bytes[4])->toBe(0x00);
        });

        it('throws TransportException for an unsupported serializer', function () {
            RawSocketHandshake::build('wamp.2.sconosciuto');
        })->throws(TransportException::class, 'Unsupported serializer for RawSocket');
    });

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    describe('parse()', function () {

        it('parses a valid JSON response', function () {
            // Build a valid response: magic + maxLen=15 + json=1 + 0 + 0
            $byte1 = (15 << 4) | 1; // maxLength=15, json=1
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            $result = RawSocketHandshake::parse($response);

            expect($result['subprotocol'])->toBe('wamp.2.json')
                ->and($result['max_message_size'])->toBe(2 ** 24);
        });

        it('parses a valid MessagePack response', function () {
            $byte1 = (15 << 4) | 2; // maxLength=15, msgpack=2
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            $result = RawSocketHandshake::parse($response);

            expect($result['subprotocol'])->toBe('wamp.2.msgpack');
        });

        it('throws TransportException for a response of the wrong length', function () {
            RawSocketHandshake::parse('abc'); // only 3 bytes
        })->throws(TransportException::class, '4 byte');

        it('throws TransportException for an invalid magic byte', function () {
            $response = pack('C4', 0x00, 0xF1, 0x00, 0x00); // invalid magic
            RawSocketHandshake::parse($response);
        })->throws(TransportException::class, 'Invalid RawSocket magic byte');

        it('throws TransportException when the router refuses the connection', function () {
            // maxLength=0 means error; the low nibble is the error code
            $byte1 = (0 << 4) | 1; // maxLength=0 → error, code=1 (unsupported serializer)
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            RawSocketHandshake::parse($response);
        })->throws(TransportException::class, 'refused connection');
    });
});
