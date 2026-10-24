<?php

use Amp\DeferredFuture;
use Hermod\LaravelWamp\Exceptions\RpcException;
use Hermod\LaravelWamp\Rpc\PendingCall;
use Hermod\LaravelWamp\Rpc\PendingCallRegistry;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Revolt\EventLoop;

describe('PendingCallRegistry', function () {

    beforeEach(function () {
        $this->registry = new PendingCallRegistry(new RequestIdGenerator);
    });

    it('registers a pending call and returns a PendingCall', function () {
        $pending = $this->registry->register('com.myapp.test');

        expect($pending)
            ->toBeInstanceOf(PendingCall::class)
            ->and($pending->procedure)->toBe('com.myapp.test')
            ->and($pending->requestId)->toBeInt();
    });

    it('retrieves a pending call by requestId', function () {
        $pending = $this->registry->register('com.myapp.test');
        $retrieved = $this->registry->get($pending->requestId);

        expect($retrieved)->toBe($pending);
    });

    it('removes the call after pull()', function () {
        $pending = $this->registry->register('com.myapp.test');
        $this->registry->pull($pending->requestId);

        expect(fn () => $this->registry->get($pending->requestId))
            ->toThrow(RpcException::class);
    });

    it('throws for an unknown requestId', function () {
        $this->registry->get(99999);
    })->throws(RpcException::class, 'No pending call found');

    it('keeps an accurate count', function () {
        expect($this->registry->count())->toBe(0)
            ->and($this->registry->isEmpty())->toBeTrue();

        $p1 = $this->registry->register('com.myapp.uno');
        $p2 = $this->registry->register('com.myapp.due');

        expect($this->registry->count())->toBe(2)
            ->and($this->registry->isEmpty())->toBeFalse();

        $this->registry->pull($p1->requestId);

        expect($this->registry->count())->toBe(1);
    });

    it('rejects all pending calls with rejectAll()', function () {
        $p1 = $this->registry->register('com.myapp.uno');
        $p2 = $this->registry->register('com.myapp.due');

        $error = new RuntimeException('Connessione persa');
        $this->registry->rejectAll($error);

        expect($this->registry->isEmpty())->toBeTrue();
    });

    it('rejects calls nobody awaits without reporting them to the event loop', function () {
        $reported = [];
        EventLoop::setErrorHandler(function (Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        try {
            // Control: an errored future nobody awaits IS reported to the event loop
            (new DeferredFuture)->error(new RuntimeException('control'));
            \Amp\delay(0);
            expect($reported)->toHaveCount(1);

            // A call rejected by rejectAll() and never awaited is not
            $this->registry->register('com.myapp.uno');
            $this->registry->rejectAll(new RuntimeException('Connection lost'));
            \Amp\delay(0);
            expect($reported)->toHaveCount(1);
        } finally {
            EventLoop::setErrorHandler(null);
        }
    });
});
