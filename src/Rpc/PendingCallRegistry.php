<?php

namespace Hermod\LaravelWamp\Rpc;

use Hermod\LaravelWamp\Exceptions\RpcException;

/**
 * Registry managing active, pending remote procedure calls (RPC).
 *
 * Tracks ongoing call lifecycles by generating unique request IDs, storing 
 * corresponding PendingCall instances, and handling batch rejections during connection drops.
 */
class PendingCallRegistry
{
    /** @var array<int, PendingCall> Mapping of requestId to PendingCall instances */
    private array $pending = [];

    /**
     * Create a new PendingCallRegistry instance.
     *
     * @param  \Hermod\LaravelWamp\Rpc\RequestIdGenerator  $idGenerator  The request ID generator service.
     */
    public function __construct(
        private readonly RequestIdGenerator $idGenerator,
    ) {
    }

    /**
     * Register a new pending RPC call, generate a unique request ID, and return the call instance.
     *
     * @param  string  $procedure  The URI of the procedure being called.
     * @return PendingCall The newly registered pending call instance.
     */
    public function register(string $procedure): PendingCall
    {
        $requestId = $this->generateUniqueId();

        $call = new PendingCall(
            requestId: $requestId,
            procedure: $procedure,
        );

        $this->pending[$requestId] = $call;

        return $call;
    }

    /**
     * Retrieve a pending call by its request ID without removing it from the registry.
     *
     * @param  int  $requestId  The unique request ID.
     * @return PendingCall The matching pending call instance.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\RpcException If no pending call is found for the given ID.
     */
    public function get(int $requestId): PendingCall
    {
        if (!isset($this->pending[$requestId])) {
            throw new RpcException(
                "No pending call found for request ID: {$requestId}",
            );
        }

        return $this->pending[$requestId];
    }

    /**
     * Remove and return a pending call by its request ID (pull operation).
     *
     * @param  int  $requestId  The unique request ID.
     * @return PendingCall The removed pending call instance.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\RpcException If no pending call is found.
     */
    public function pull(int $requestId): PendingCall
    {
        $call = $this->get($requestId);
        unset($this->pending[$requestId]);

        return $call;
    }

    /**
     * Reject all currently pending calls with a given exception — typically used during disconnections.
     *
     * @param  \Throwable  $e  The exception used to reject all pending futures.
     */
    public function rejectAll(\Throwable $e): void
    {
        foreach ($this->pending as $call) {
            // The caller may never await this future: don't report the error to the event loop
            $call->getFuture()->ignore();
            $call->reject($e);
        }

        $this->pending = [];
    }

    /**
     * Get the total count of active pending calls.
     *
     * @return int The number of pending calls.
     */
    public function count(): int
    {
        return count($this->pending);
    }

    /**
     * Determine whether the registry has no pending calls.
     *
     * @return bool True if empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return empty($this->pending);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a guaranteed unique request ID, avoiding any collisions with existing pending entries.
     *
     * @return int A unique request ID integer.
     */
    private function generateUniqueId(): int
    {
        // Avoid collisions in the rare event of a duplicate ID generation
        do {
            $id = $this->idGenerator->generate();
        } while (isset($this->pending[$id]));

        return $id;
    }
}