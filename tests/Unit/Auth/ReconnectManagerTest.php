<?php

use Hermod\LaravelWamp\Exceptions\ReconnectException;
use Hermod\LaravelWamp\Reconnect\ExponentialBackoffStrategy;
use Hermod\LaravelWamp\Reconnect\ReconnectManager;

describe('ReconnectManager', function () {

    // -------------------------------------------------------------------------
    // Initial state
    // -------------------------------------------------------------------------

    it('does not start in reconnecting state', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        expect($manager->isReconnecting())->toBeFalse();
    });

    it('reports whether it is enabled or disabled', function () {
        $enabled = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );
        $disabled = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: false,
        );

        expect($enabled->isEnabled())->toBeTrue()
            ->and($disabled->isEnabled())->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Successful reconnect
    // -------------------------------------------------------------------------

    it('calls onSuccess after a successful reconnect', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $successCalled = false;
        $connectCalls = 0;

        $manager->reconnect(
            connectFn: function () use (&$connectCalls) {
                $connectCalls++;
                // Succeeds on the first attempt
            },
            onSuccess: function () use (&$successCalled) {
                $successCalled = true;
            },
        );

        expect($successCalled)->toBeTrue()
            ->and($connectCalls)->toBe(1);
    });

    it('retries after a failure and succeeds on the second attempt', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $attempts = 0;
        $successCalled = false;

        $manager->reconnect(
            connectFn: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new RuntimeException('Connessione fallita');
                }
                // Succeeds on the second attempt
            },
            onSuccess: function () use (&$successCalled) {
                $successCalled = true;
            },
        );

        expect($successCalled)->toBeTrue()
            ->and($attempts)->toBe(2);
    });

    // -------------------------------------------------------------------------
    // Failed reconnect
    // -------------------------------------------------------------------------

    it('throws ReconnectException when attempts are exhausted', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $attempts = 0;

        expect(function () use ($manager, &$attempts) {
            $manager->reconnect(
                connectFn: function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('Connessione sempre fallita');
                },
                onSuccess: fn () => null,
            );
        })->toThrow(ReconnectException::class, 'Reconnect failed after');

        expect($attempts)->toBe(3);
    });

    // -------------------------------------------------------------------------
    // Reconnect disabled
    // -------------------------------------------------------------------------

    it('throws ReconnectException immediately when disabled', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: false,
        );

        expect(fn () => $manager->reconnect(
            connectFn: fn () => null,
            onSuccess: fn () => null,
        ))->toThrow(ReconnectException::class, 'is disabled');
    });

    // -------------------------------------------------------------------------
    // Reset after reconnect
    // -------------------------------------------------------------------------

    it('resets the strategy after a successful reconnect', function () {
        $strategy = new ExponentialBackoffStrategy(5, 0.001, 1.0, 2.0);
        $manager = new ReconnectManager(strategy: $strategy, enabled: true);

        $attempts = 0;

        // First reconnect — fails twice, then succeeds
        $manager->reconnect(
            connectFn: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('Fallito');
                }
            },
            onSuccess: fn () => null,
        );

        // After the reset the strategy should start again from 0
        expect($strategy->attempts())->toBe(0)
            ->and($strategy->nextDelay())->toBe(0.001);
    });
});
