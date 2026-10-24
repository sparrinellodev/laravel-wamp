<?php

use Hermod\LaravelWamp\Exceptions\CalleeException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Rpc\Callee;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Session\WampSession;

describe('Callee', function () {

    beforeEach(function () {
        $this->session = Mockery::mock(WampSession::class);
        $this->callee = new Callee($this->session, new RequestIdGenerator);
    });

    afterEach(fn () => Mockery::close());

    it('sends REGISTER to the session when a procedure is registered', function () {
        $this->session
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (WampMessage $message) {
                return $message->type() === MessageType::REGISTER
                    && $message->get(3) === 'com.myapp.somma';
            });

        $this->callee->register('com.myapp.somma', fn ($args) => $args[0] + $args[1]);

        expect(true)->toBeTrue();
    });

    it('throws when the same procedure is registered twice', function () {
        $this->session->shouldReceive('send')->once();

        $this->callee->register('com.myapp.somma', fn () => null);

        expect(fn () => $this->callee->register('com.myapp.somma', fn () => null))
            ->toThrow(CalleeException::class, 'is already registered');
    });

    it('runs the handler and sends YIELD on INVOCATION', function () {
        $this->session->shouldReceive('send')->twice();

        $this->callee->register('com.myapp.somma', fn ($args) => $args[0] + $args[1]);

        // Simulate REGISTERED from the router
        // [65, requestId, registrationId]
        $pendingData = (new ReflectionProperty($this->callee, 'pendingRegistrations'))
            ->getValue($this->callee);

        $requestId = array_values($pendingData)[0]['requestId'];

        $registered = WampMessage::fromArray([65, $requestId, 1001]);
        $this->callee->onRegistered($registered);

        // Check that YIELD carries the correct result
        $this->session
            ->shouldReceive('send')
            ->withArgs(function (WampMessage $message) {
                return $message->type() === MessageType::YIELD
                    && $message->get(3) === [8];
            });

        // Simulate INVOCATION from the router
        // [68, invRequestId, registrationId, {}, [3, 5]]
        $invocation = WampMessage::fromArray([68, 555, 1001, [], [3, 5]]);
        $this->callee->onInvocation($invocation);
    });

    it('sends ERROR when the handler throws', function () {
        $this->session->shouldReceive('send')->twice();

        $this->callee->register('com.myapp.fallisce', function () {
            throw new RuntimeException('Qualcosa è andato storto');
        });

        $pendingData = (new ReflectionProperty($this->callee, 'pendingRegistrations'))
            ->getValue($this->callee);

        $requestId = array_values($pendingData)[0]['requestId'];
        $registered = WampMessage::fromArray([65, $requestId, 2001]);
        $this->callee->onRegistered($registered);

        $this->session
            ->shouldReceive('send')
            ->withArgs(function (WampMessage $message) {
                return $message->type() === MessageType::ERROR
                    && $message->get(4) === 'wamp.error.runtime_error';
            });

        $invocation = WampMessage::fromArray([68, 666, 2001, [], []]);
        $this->callee->onInvocation($invocation);
    });

    it('returns the active registrations', function () {
        $this->session->shouldReceive('send')->once();

        $handler = fn ($args) => $args[0] * 2;
        $this->callee->register('com.myapp.doppio', $handler);

        $pendingData = (new ReflectionProperty($this->callee, 'pendingRegistrations'))
            ->getValue($this->callee);

        $requestId = array_values($pendingData)[0]['requestId'];
        $registered = WampMessage::fromArray([65, $requestId, 3001]);
        $this->callee->onRegistered($registered);

        expect($this->callee->getRegistrations())
            ->toHaveKey('com.myapp.doppio');
    });

    it('flush() returns active and pending handlers and clears the local state', function () {
        $this->session->shouldReceive('send')->times(4);

        $sum = fn ($args) => $args[0] + $args[1];
        $double = fn ($args) => $args[0] * 2;

        $this->callee->register('com.myapp.somma', $sum);
        $this->callee->register('com.myapp.doppio', $double);

        // Confirm only the first registration: the second one stays pending
        $pendingData = (new ReflectionProperty($this->callee, 'pendingRegistrations'))
            ->getValue($this->callee);
        $this->callee->onRegistered(WampMessage::fromArray([65, $pendingData['com.myapp.somma']['requestId'], 1001]));

        expect($this->callee->flush())->toBe([
            'com.myapp.somma' => $sum,
            'com.myapp.doppio' => $double,
        ])
            ->and($this->callee->getRegistrations())->toBe([]);

        // Both procedures can be registered again on a new session
        $this->callee->register('com.myapp.somma', $sum);
        $this->callee->register('com.myapp.doppio', $double);
    });
});
