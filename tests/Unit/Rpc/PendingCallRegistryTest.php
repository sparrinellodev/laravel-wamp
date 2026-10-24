<?php

use Hermod\LaravelWamp\Exceptions\RpcException;
use Hermod\LaravelWamp\Rpc\PendingCall;
use Hermod\LaravelWamp\Rpc\PendingCallRegistry;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;

describe('PendingCallRegistry', function () {

    beforeEach(function () {
        $this->registry = new PendingCallRegistry(new RequestIdGenerator);
    });

    it('registra una chiamata pendente e restituisce un PendingCall', function () {
        $pending = $this->registry->register('com.myapp.test');

        expect($pending)
            ->toBeInstanceOf(PendingCall::class)
            ->and($pending->procedure)->toBe('com.myapp.test')
            ->and($pending->requestId)->toBeInt();
    });

    it('recupera una chiamata pendente tramite requestId', function () {
        $pending = $this->registry->register('com.myapp.test');
        $retrieved = $this->registry->get($pending->requestId);

        expect($retrieved)->toBe($pending);
    });

    it('rimuove la chiamata dopo pull()', function () {
        $pending = $this->registry->register('com.myapp.test');
        $this->registry->pull($pending->requestId);

        expect(fn () => $this->registry->get($pending->requestId))
            ->toThrow(RpcException::class);
    });

    it('lancia eccezione per requestId inesistente', function () {
        $this->registry->get(99999);
    })->throws(RpcException::class, 'No pending call found');

    it('tiene traccia del conteggio corretto', function () {
        expect($this->registry->count())->toBe(0)
            ->and($this->registry->isEmpty())->toBeTrue();

        $p1 = $this->registry->register('com.myapp.uno');
        $p2 = $this->registry->register('com.myapp.due');

        expect($this->registry->count())->toBe(2)
            ->and($this->registry->isEmpty())->toBeFalse();

        $this->registry->pull($p1->requestId);

        expect($this->registry->count())->toBe(1);
    });

    it('rigetta tutte le chiamate pendenti con rejectAll()', function () {
        $p1 = $this->registry->register('com.myapp.uno');
        $p2 = $this->registry->register('com.myapp.due');

        $error = new RuntimeException('Connessione persa');
        $this->registry->rejectAll($error);

        expect($this->registry->isEmpty())->toBeTrue();
    });
});
