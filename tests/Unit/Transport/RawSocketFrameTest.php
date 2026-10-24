<?php

use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Transport\RawSocket\RawSocketFrame;

describe('RawSocketFrame', function () {

    // -------------------------------------------------------------------------
    // encode()
    // -------------------------------------------------------------------------

    describe('encode()', function () {

        it('produce un frame di almeno 4 byte (header)', function () {
            $frame = RawSocketFrame::encode('');

            expect(strlen($frame))->toBe(4);
        });

        it('include il payload dopo i 4 byte di header', function () {
            $payload = 'messaggio di test';
            $frame = RawSocketFrame::encode($payload);

            expect(strlen($frame))->toBe(4 + strlen($payload))
                ->and(substr($frame, 4))->toBe($payload);
        });

        it('imposta il tipo corretto nel primo byte', function () {
            $regular = RawSocketFrame::encode('', RawSocketFrame::TYPE_REGULAR);
            $ping = RawSocketFrame::encode('', RawSocketFrame::TYPE_PING);
            $pong = RawSocketFrame::encode('', RawSocketFrame::TYPE_PONG);

            $bytes = unpack('C', $regular);
            expect($bytes[1] & 0x0F)->toBe(RawSocketFrame::TYPE_REGULAR);

            $bytes = unpack('C', $ping);
            expect($bytes[1] & 0x0F)->toBe(RawSocketFrame::TYPE_PING);

            $bytes = unpack('C', $pong);
            expect($bytes[1] & 0x0F)->toBe(RawSocketFrame::TYPE_PONG);
        });

        it('codifica la lunghezza del payload in big-endian su 3 byte', function () {
            $payload = str_repeat('A', 300); // 300 byte
            $frame = RawSocketFrame::encode($payload);

            $bytes = unpack('C4', substr($frame, 0, 4));
            $length = ($bytes[2] << 16) | ($bytes[3] << 8) | $bytes[4];

            expect($length)->toBe(300);
        });

        it('gestisce payload vuoto', function () {
            $frame = RawSocketFrame::encode('');
            $bytes = unpack('C4', $frame);

            $length = ($bytes[2] << 16) | ($bytes[3] << 8) | $bytes[4];
            expect($length)->toBe(0);
        });

        it('lancia TransportException per payload troppo grande', function () {
            // 0xFFFFFF + 1 = troppo grande
            $payload = str_repeat('X', 0xFFFFFF + 1);
            RawSocketFrame::encode($payload);
        })->throws(TransportException::class, 'Payload too large');
    });

    // -------------------------------------------------------------------------
    // decodeHeader()
    // -------------------------------------------------------------------------

    describe('decodeHeader()', function () {

        it('decodifica correttamente tipo e lunghezza', function () {
            // Creiamo un header per un frame regular con payload di 1234 byte
            $length = 1234;
            $header = pack('C', RawSocketFrame::TYPE_REGULAR)
                .pack('C', ($length >> 16) & 0xFF)
                .pack('C', ($length >> 8) & 0xFF)
                .pack('C', $length & 0xFF);

            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_REGULAR)
                ->and($result['length'])->toBe(1234);
        });

        it('riconosce il tipo ping', function () {
            $frame = RawSocketFrame::ping('test');
            $header = substr($frame, 0, 4);
            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_PING);
        });

        it('riconosce il tipo pong', function () {
            $frame = RawSocketFrame::pong('test');
            $header = substr($frame, 0, 4);
            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_PONG);
        });

        it('lancia TransportException per header di lunghezza errata', function () {
            RawSocketFrame::decodeHeader('abc'); // solo 3 byte
        })->throws(TransportException::class, '4 byte');
    });

    // -------------------------------------------------------------------------
    // Round-trip
    // -------------------------------------------------------------------------

    describe('round-trip encode/decode', function () {

        it('encode e decodeHeader sono coerenti', function () {
            $payload = 'messaggio WAMP di test con caratteri speciali: àèìòù';
            $frame = RawSocketFrame::encode($payload);
            $header = substr($frame, 0, 4);
            $decoded = RawSocketFrame::decodeHeader($header);

            expect($decoded['type'])->toBe(RawSocketFrame::TYPE_REGULAR)
                ->and($decoded['length'])->toBe(strlen($payload))
                ->and(substr($frame, 4, $decoded['length']))->toBe($payload);
        });
    });

    // -------------------------------------------------------------------------
    // ping() e pong()
    // -------------------------------------------------------------------------

    describe('ping() e pong()', function () {

        it('ping() crea un frame ping con payload', function () {
            $frame = RawSocketFrame::ping('test-ping');
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PING)
                ->and($header['length'])->toBe(strlen('test-ping'))
                ->and(substr($frame, 4))->toBe('test-ping');
        });

        it('pong() crea un frame pong con payload', function () {
            $frame = RawSocketFrame::pong('test-pong');
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PONG)
                ->and($header['length'])->toBe(strlen('test-pong'))
                ->and(substr($frame, 4))->toBe('test-pong');
        });

        it('ping() funziona con payload vuoto', function () {
            $frame = RawSocketFrame::ping();
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PING)
                ->and($header['length'])->toBe(0);
        });
    });
});
