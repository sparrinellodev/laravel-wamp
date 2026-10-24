<?php

namespace Hermod\LaravelWamp\Reconnect;

use Hermod\LaravelWamp\Contracts\ReconnectStrategyContract;
use Hermod\LaravelWamp\Exceptions\ReconnectException;
use Throwable;

/**
 * Manages automated connection recovery and retry loops using pluggable reconnect strategies.
 *
 * Coordinates asynchronous delay intervals via AMPHP, executes reconnection callbacks, 
 * tracks retry states, and handles failure lifecycles until recovery succeeds or max limits are reached.
 */
class ReconnectManager
{
    /** @var bool Flag indicating whether a reconnection sequence is currently active. */
    private bool $reconnecting = false;

    /**
     * Create a new ReconnectManager instance.
     *
     * @param  \Hermod\LaravelWamp\Contracts\ReconnectStrategyContract  $strategy  The underlying retry strategy (e.g., exponential backoff).
     * @param  bool  $enabled  Whether automatic reconnection is enabled.
     */
    public function __construct(
        private readonly ReconnectStrategyContract $strategy,
        private readonly bool $enabled,
    ) {
    }

    /**
     * Determine whether automatic reconnection is enabled.
     *
     * @return bool True if enabled, false otherwise.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Determine whether the manager is currently attempting to reconnect.
     *
     * @return bool True if reconnecting, false otherwise.
     */
    public function isReconnecting(): bool
    {
        return $this->reconnecting;
    }

    /**
     * Execute the reconnection loop using the configured strategy.
     *
     * Repeatedly invokes the connection callback with delayed intervals until connection succeeds
     * or all retry attempts are exhausted. A failure in either callback counts as a failed attempt.
     *
     * @param  callable  $connectFn  The callback function attempting to establish connection.
     * @param  callable  $onSuccess  The callback executed upon a successful reconnection (e.g. restoring registrations).
     *
     * @throws \Hermod\LaravelWamp\Exceptions\ReconnectException If automatic reconnection is disabled or all attempts fail.
     */
    public function reconnect(callable $connectFn, callable $onSuccess): void
    {
        if (!$this->enabled) {
            throw new ReconnectException(
                'Automatic reconnection is disabled in configuration.',
            );
        }

        $this->reconnecting = true;
        $this->strategy->reset();

        while ($this->strategy->shouldRetry()) {
            $delay = $this->strategy->nextDelay();
            $attempt = $this->strategy->attempts() + 1;

            // Wait before making the retry attempt using AMPHP delay
            \Amp\delay($delay);

            try {
                $connectFn();
                $onSuccess();
            } catch (Throwable $e) {
                $this->strategy->recordFailure();

                // If this was the last attempt, throw an exception
                if (!$this->strategy->shouldRetry()) {
                    $this->reconnecting = false;
                    throw new ReconnectException(
                        "Reconnect failed after {$attempt} attempts: {$e->getMessage()}",
                        previous: $e,
                    );
                }

                continue;
            }

            // Reset only once the session is fully restored, so that a failing
            // onSuccess still counts towards the attempt limit
            $this->strategy->reset();
            $this->reconnecting = false;

            return;
        }

        $this->reconnecting = false;
    }
}