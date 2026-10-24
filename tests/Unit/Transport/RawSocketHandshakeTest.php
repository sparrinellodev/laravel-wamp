<?php

use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Transport\RawSocket\RawSocketHandshake;

describe('RawSocketHandshake', function () {

    // -------------------------------------------------------------------------
    // build()
    // -------------------------------------------------------------------------

    describe('build()', function () {

        it('costruisce un handshake JSON valido di 4 byte', function () {
            $result = RawSocketHandshake::build('wamp.2.json');

            expect(strlen($result))->toBe(4);

            $bytes = unpack('C4', $result);
            expect($bytes[1])->toBe(0x7F); // magic byte
        });

        it('usa il codice serializzatore corretto per JSON', function () {
            $result = RawSocketHandshake::build('wamp.2.json');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(1); // JSON = 1
        });

        it('usa il codice serializzatore corretto per MessagePack', function () {
            $result = RawSocketHandshake::build('wamp.2.msgpack');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(2); // MessagePack = 2
        });

        it('usa il codice serializzatore corretto per CBOR', function () {
            $result = RawSocketHandshake::build('wamp.2.cbor');
            $bytes = unpack('C4', $result);

            $serializerCode = $bytes[2] & 0x0F;
            expect($serializerCode)->toBe(3); // CBOR = 3
        });

        it('imposta i byte riservati a zero', function () {
            $result = RawSocketHandshake::build('wamp.2.json');
            $bytes = unpack('C4', $result);

            expect($bytes[3])->toBe(0x00)
                ->and($bytes[4])->toBe(0x00);
        });

        it('lancia TransportException per serializzatore non supportato', function () {
            RawSocketHandshake::build('wamp.2.sconosciuto');
        })->throws(TransportException::class, 'Unsupported serializer for RawSocket');
    });

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    describe('parse()', function () {

        it('analizza una risposta valida JSON', function () {
            // Costruiamo una risposta valida: magic + maxLen=15 + json=1 + 0 + 0
            $byte1 = (15 << 4) | 1; // maxLength=15, json=1
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            $result = RawSocketHandshake::parse($response);

            expect($result['subprotocol'])->toBe('wamp.2.json')
                ->and($result['max_message_size'])->toBe(2 ** 24);
        });

        it('analizza una risposta valida MessagePack', function () {
            $byte1 = (15 << 4) | 2; // maxLength=15, msgpack=2
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            $result = RawSocketHandshake::parse($response);

            expect($result['subprotocol'])->toBe('wamp.2.msgpack');
        });

        it('lancia TransportException per lunghezza risposta errata', function () {
            RawSocketHandshake::parse('abc'); // solo 3 byte
        })->throws(TransportException::class, '4 byte');

        it('lancia TransportException per magic byte errato', function () {
            $response = pack('C4', 0x00, 0xF1, 0x00, 0x00); // magic errato
            RawSocketHandshake::parse($response);
        })->throws(TransportException::class, 'Invalid RawSocket magic byte');

        it('lancia TransportException quando il router rifiuta la connessione', function () {
            // maxLength=0 significa errore, il nibble basso è il codice errore
            $byte1 = (0 << 4) | 1; // maxLength=0 → errore, codice=1 (serializzatore non supportato)
            $response = pack('C4', 0x7F, $byte1, 0x00, 0x00);

            RawSocketHandshake::parse($response);
        })->throws(TransportException::class, 'refused connection');
    });
});
