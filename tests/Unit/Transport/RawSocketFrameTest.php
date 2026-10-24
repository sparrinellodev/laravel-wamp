<?php

use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Transport\RawSocket\RawSocketFrame;

describe('RawSocketFrame', function () {

    // -------------------------------------------------------------------------
    // encode()
    // -------------------------------------------------------------------------

    describe('encode()', function () {

        it('produces a frame of at least 4 bytes (header)', function () {
            $frame = RawSocketFrame::encode('');

            expect(strlen($frame))->toBe(4);
        });

        it('appends the payload after the 4-byte header', function () {
            $payload = 'messaggio di test';
            $frame = RawSocketFrame::encode($payload);

            expect(strlen($frame))->toBe(4 + strlen($payload))
                ->and(substr($frame, 4))->toBe($payload);
        });

        it('sets the frame type in the first byte', function () {
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

        it('encodes the payload length as 3-byte big-endian', function () {
            $payload = str_repeat('A', 300); // 300 bytes
            $frame = RawSocketFrame::encode($payload);

            $bytes = unpack('C4', substr($frame, 0, 4));
            $length = ($bytes[2] << 16) | ($bytes[3] << 8) | $bytes[4];

            expect($length)->toBe(300);
        });

        it('handles an empty payload', function () {
            $frame = RawSocketFrame::encode('');
            $bytes = unpack('C4', $frame);

            $length = ($bytes[2] << 16) | ($bytes[3] << 8) | $bytes[4];
            expect($length)->toBe(0);
        });

        it('throws TransportException for an oversized payload', function () {
            // 0xFFFFFF + 1 = too large
            $payload = str_repeat('X', 0xFFFFFF + 1);
            RawSocketFrame::encode($payload);
        })->throws(TransportException::class, 'Payload too large');
    });

    // -------------------------------------------------------------------------
    // decodeHeader()
    // -------------------------------------------------------------------------

    describe('decodeHeader()', function () {

        it('decodes type and length', function () {
            // Build a header for a regular frame with a 1234-byte payload
            $length = 1234;
            $header = pack('C', RawSocketFrame::TYPE_REGULAR)
                .pack('C', ($length >> 16) & 0xFF)
                .pack('C', ($length >> 8) & 0xFF)
                .pack('C', $length & 0xFF);

            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_REGULAR)
                ->and($result['length'])->toBe(1234);
        });

        it('recognizes the ping type', function () {
            $frame = RawSocketFrame::ping('test');
            $header = substr($frame, 0, 4);
            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_PING);
        });

        it('recognizes the pong type', function () {
            $frame = RawSocketFrame::pong('test');
            $header = substr($frame, 0, 4);
            $result = RawSocketFrame::decodeHeader($header);

            expect($result['type'])->toBe(RawSocketFrame::TYPE_PONG);
        });

        it('throws TransportException for a header of the wrong length', function () {
            RawSocketFrame::decodeHeader('abc'); // only 3 bytes
        })->throws(TransportException::class, '4 byte');
    });

    // -------------------------------------------------------------------------
    // Round-trip
    // -------------------------------------------------------------------------

    describe('round-trip encode/decode', function () {

        it('keeps encode and decodeHeader consistent', function () {
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
    // ping() and pong()
    // -------------------------------------------------------------------------

    describe('ping() and pong()', function () {

        it('ping() creates a ping frame with a payload', function () {
            $frame = RawSocketFrame::ping('test-ping');
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PING)
                ->and($header['length'])->toBe(strlen('test-ping'))
                ->and(substr($frame, 4))->toBe('test-ping');
        });

        it('pong() creates a pong frame with a payload', function () {
            $frame = RawSocketFrame::pong('test-pong');
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PONG)
                ->and($header['length'])->toBe(strlen('test-pong'))
                ->and(substr($frame, 4))->toBe('test-pong');
        });

        it('ping() works with an empty payload', function () {
            $frame = RawSocketFrame::ping();
            $header = RawSocketFrame::decodeHeader(substr($frame, 0, 4));

            expect($header['type'])->toBe(RawSocketFrame::TYPE_PING)
                ->and($header['length'])->toBe(0);
        });
    });
});
