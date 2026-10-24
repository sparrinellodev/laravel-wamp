<?php

use Hermod\LaravelWamp\Reconnect\ExponentialBackoffStrategy;

describe('ExponentialBackoffStrategy', function () {

    beforeEach(function () {
        $this->strategy = new ExponentialBackoffStrategy(
            maxAttempts: 5,
            baseDelay: 1.0,
            maxDelay: 30.0,
            multiplier: 2.0,
        );
    });

    it('starts with zero attempts', function () {
        expect($this->strategy->attempts())->toBe(0);
    });

    it('allows retries until attempts are exhausted', function () {
        expect($this->strategy->shouldRetry())->toBeTrue();

        for ($i = 0; $i < 5; $i++) {
            $this->strategy->recordFailure();
        }

        expect($this->strategy->shouldRetry())->toBeFalse();
    });

    it('starts with the base delay', function () {
        expect($this->strategy->nextDelay())->toBe(1.0);
    });

    it('applies exponential backoff', function () {
        expect($this->strategy->nextDelay())->toBe(1.0);  // before the first failure

        $this->strategy->recordFailure();
        expect($this->strategy->nextDelay())->toBe(2.0);  // 1.0 * 2

        $this->strategy->recordFailure();
        expect($this->strategy->nextDelay())->toBe(4.0);  // 2.0 * 2

        $this->strategy->recordFailure();
        expect($this->strategy->nextDelay())->toBe(8.0);  // 4.0 * 2

        $this->strategy->recordFailure();
        expect($this->strategy->nextDelay())->toBe(16.0); // 8.0 * 2
    });

    it('never exceeds the max delay', function () {
        // With maxDelay=30 and multiplier=2 starting from 1,
        // after 5 recordFailure calls the delay would be 32 → capped at 30
        for ($i = 0; $i < 5; $i++) {
            $this->strategy->recordFailure();
        }

        expect($this->strategy->nextDelay())->toBe(30.0);
    });

    it('increments the attempt counter', function () {
        $this->strategy->recordFailure();
        expect($this->strategy->attempts())->toBe(1);

        $this->strategy->recordFailure();
        expect($this->strategy->attempts())->toBe(2);

        $this->strategy->recordFailure();
        expect($this->strategy->attempts())->toBe(3);
    });

    it('resets state after reset()', function () {
        $this->strategy->recordFailure();
        $this->strategy->recordFailure();
        $this->strategy->recordFailure();

        $this->strategy->reset();

        expect($this->strategy->attempts())->toBe(0)
            ->and($this->strategy->nextDelay())->toBe(1.0)
            ->and($this->strategy->shouldRetry())->toBeTrue();
    });

    it('works with maxAttempts=1', function () {
        $strategy = new ExponentialBackoffStrategy(
            maxAttempts: 1,
            baseDelay: 1.0,
            maxDelay: 30.0,
            multiplier: 2.0,
        );

        expect($strategy->shouldRetry())->toBeTrue();

        $strategy->recordFailure();

        expect($strategy->shouldRetry())->toBeFalse();
    });

    it('works with multiplier=1 (constant delay)', function () {
        $strategy = new ExponentialBackoffStrategy(
            maxAttempts: 3,
            baseDelay: 5.0,
            maxDelay: 30.0,
            multiplier: 1.0,
        );

        $strategy->recordFailure();
        expect($strategy->nextDelay())->toBe(5.0);

        $strategy->recordFailure();
        expect($strategy->nextDelay())->toBe(5.0);
    });
});
