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

    it('invia REGISTER alla sessione quando si registra una procedura', function () {
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

    it('lancia eccezione se si registra la stessa procedura due volte', function () {
        $this->session->shouldReceive('send')->once();

        $this->callee->register('com.myapp.somma', fn () => null);

        expect(fn () => $this->callee->register('com.myapp.somma', fn () => null))
            ->toThrow(CalleeException::class, 'is already registered');
    });

    it('esegue l\'handler e invia YIELD quando riceve INVOCATION', function () {
        $this->session->shouldReceive('send')->twice();

        $this->callee->register('com.myapp.somma', fn ($args) => $args[0] + $args[1]);

        // Simuliamo REGISTERED dal router
        // [65, requestId, registrationId]
        $pendingData = (new ReflectionProperty($this->callee, 'pendingRegistrations'))
            ->getValue($this->callee);

        $requestId = array_values($pendingData)[0]['requestId'];

        $registered = WampMessage::fromArray([65, $requestId, 1001]);
        $this->callee->onRegistered($registered);

        // Verifichiamo che YIELD contenga il risultato corretto
        $this->session
            ->shouldReceive('send')
            ->withArgs(function (WampMessage $message) {
                return $message->type() === MessageType::YIELD
                    && $message->get(3) === [8];
            });

        // Simuliamo INVOCATION dal router
        // [68, invRequestId, registrationId, {}, [3, 5]]
        $invocation = WampMessage::fromArray([68, 555, 1001, [], [3, 5]]);
        $this->callee->onInvocation($invocation);
    });

    it('invia ERROR se l\'handler lancia un\'eccezione', function () {
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

    it('restituisce le registrazioni attive', function () {
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
});
