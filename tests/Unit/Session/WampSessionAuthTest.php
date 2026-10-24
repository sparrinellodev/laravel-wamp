<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;
use Hermod\LaravelWamp\Auth\WampCraAuthenticator;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Contracts\TransportContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;
use Hermod\LaravelWamp\Exceptions\WampProtocolException;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Session\SessionState;
use Hermod\LaravelWamp\Session\WampSession;

describe('WampSession — Auth', function () {

    beforeEach(function () {
        $this->transport = Mockery::mock(TransportContract::class);
        $this->serializer = new JsonSerializer;
    });

    afterEach(fn () => Mockery::close());

    // -------------------------------------------------------------------------
    // Anonymous
    // -------------------------------------------------------------------------

    describe('Anonymous auth', function () {

        it('establishes the session on WELCOME after an anonymous HELLO', function () {
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new AnonymousAuthenticator,
            );

            $welcome = json_encode([2, 111222, ['roles' => []]]);

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->once()->withArgs(function (string $raw) {
                $data = json_decode($raw, true);

                // HELLO must list anonymous in authmethods
                return $data[0] === 1
                    && $data[1] === 'realm1'
                    && in_array('anonymous', $data[2]['authmethods'] ?? []);
            });
            $this->transport->shouldReceive('receive')->once()->andReturn($welcome);

            $session->hello();

            expect($session->isEstablished())->toBeTrue()
                ->and($session->getSessionId())->toBe(111222);
        });

        it('does not send authid in HELLO for anonymous', function () {
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new AnonymousAuthenticator,
            );

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->once()->withArgs(function (string $raw) {
                $data = json_decode($raw, true);

                return ! isset($data[2]['authid']);
            });
            $this->transport->shouldReceive('receive')->once()
                ->andReturn(json_encode([2, 999, []]));

            $session->hello();

            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Ticket auth
    // -------------------------------------------------------------------------

    describe('Ticket auth', function () {

        it('handles the HELLO → CHALLENGE → AUTHENTICATE → WELCOME sequence', function () {
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new TicketAuthenticator('user123', 'my-ticket'),
            );

            $challenge = json_encode([4, 'ticket', ['challenge' => '']]);
            $welcome = json_encode([2, 555666, ['roles' => []]]);

            $this->transport->shouldReceive('connect')->once();

            // 1st send → HELLO
            // 2nd send → AUTHENTICATE with the ticket
            $this->transport->shouldReceive('send')->twice()->withArgs(function (string $raw) {
                $data = json_decode($raw, true);

                if ($data[0] === 1) {
                    // HELLO — check authid and authmethod
                    return $data[2]['authid'] === 'user123'
                        && in_array('ticket', $data[2]['authmethods'] ?? []);
                }

                if ($data[0] === 5) {
                    // AUTHENTICATE — must carry the ticket
                    return $data[1] === 'my-ticket';
                }

                return false;
            });

            // 1st receive → CHALLENGE, 2nd receive → WELCOME
            $this->transport->shouldReceive('receive')
                ->twice()
                ->andReturn($challenge, $welcome);

            $session->hello();

            expect($session->isEstablished())->toBeTrue()
                ->and($session->getSessionId())->toBe(555666);
        });

        it('sends the ticket supplied by a provider in AUTHENTICATE', function () {
            $provider = new class implements TicketProviderContract
            {
                public function ticket(?string $authId, array $extra = []): string
                {
                    return "jwt-for-{$authId}";
                }
            };

            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new TicketAuthenticator('user123', $provider),
            );

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->twice()->withArgs(function (string $raw) {
                $data = json_decode($raw, true);

                // HELLO passes through; AUTHENTICATE must carry the provider's ticket
                return $data[0] !== 5 || $data[1] === 'jwt-for-user123';
            });
            $this->transport->shouldReceive('receive')
                ->twice()
                ->andReturn(json_encode([4, 'ticket', []]), json_encode([2, 555666, []]));

            $session->hello();

            expect($session->isEstablished())->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // WAMP-CRA auth
    // -------------------------------------------------------------------------

    describe('WAMP-CRA auth', function () {

        it('handles the HELLO → CHALLENGE → AUTHENTICATE sequence with HMAC', function () {
            $secret = 'my-cra-secret';
            $challenge = 'wampcra-challenge-string';

            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new WampCraAuthenticator('user123', $secret),
            );

            $expectedSignature = base64_encode(
                hash_hmac('sha256', $challenge, $secret, binary: true),
            );

            $challengeMsg = json_encode([4, 'wampcra', ['challenge' => $challenge]]);
            $welcome = json_encode([2, 777888, ['roles' => []]]);

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->twice()->withArgs(function (string $raw) use ($expectedSignature) {
                $data = json_decode($raw, true);

                if ($data[0] === 5) {
                    // AUTHENTICATE — check the HMAC signature
                    return $data[1] === $expectedSignature;
                }

                return true; // HELLO ok
            });

            $this->transport->shouldReceive('receive')
                ->twice()
                ->andReturn($challengeMsg, $welcome);

            $session->hello();

            expect($session->isEstablished())->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Authentication errors
    // -------------------------------------------------------------------------

    describe('Auth errors', function () {

        it('throws AuthenticationException on ABORT with an auth error', function () {
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new TicketAuthenticator('user', 'wrong-ticket'),
            );

            $challenge = json_encode([4, 'ticket', []]);
            $abort = json_encode([3, ['message' => 'not authorized'], 'wamp.error.not_authorized']);

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->twice();
            $this->transport->shouldReceive('receive')->twice()->andReturn($challenge, $abort);
            $this->transport->shouldReceive('close')->once();

            expect(fn () => $session->hello())
                ->toThrow(AuthenticationException::class, 'not_authorized');
        });

        it('throws WampProtocolException on ABORT unrelated to auth', function () {
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new AnonymousAuthenticator,
            );

            $abort = json_encode([3, [], 'wamp.error.no_such_realm']);

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->once();
            $this->transport->shouldReceive('receive')->once()->andReturn($abort);
            $this->transport->shouldReceive('close')->once();

            expect(fn () => $session->hello())
                ->toThrow(WampProtocolException::class, 'no_such_realm');
        });

        it('throws AuthenticationException on CHALLENGE for an authenticator that does not support it', function () {
            // Anonymous does not support challenges — receiving one is an error
            $session = new WampSession(
                $this->transport,
                $this->serializer,
                'realm1',
                new AnonymousAuthenticator,
            );

            $challenge = json_encode([4, 'wampcra', ['challenge' => 'abc']]);

            $this->transport->shouldReceive('connect')->once();
            $this->transport->shouldReceive('send')->once();
            $this->transport->shouldReceive('receive')->once()->andReturn($challenge);
            $this->transport->shouldReceive('close')->once();

            expect(fn () => $session->hello())
                ->toThrow(AuthenticationException::class)
                ->and($session->getState())->toBe(SessionState::Closed);
        });
    });
});
