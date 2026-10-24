<?php

namespace Hermod\LaravelWamp\Rpc;

use Amp\Future;
use Hermod\LaravelWamp\Contracts\CallerContract;
use Hermod\LaravelWamp\Exceptions\RpcException;
use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Session\WampSession;
use Throwable;

/**
 * Manages WAMP remote procedure calls (RPC) for the Caller role.
 *
 * Implements the CallerContract interface, coordinating asynchronous execution via AMPHP futures,
 * dispatching CALL messages through the session, and processing incoming RESULT or ERROR responses.
 */
class Caller implements CallerContract
{
    /**
     * Create a new Caller instance.
     *
     * @param  \Hermod\LaravelWamp\Session\WampSession  $session  The active WAMP session handler.
     * @param  \Hermod\LaravelWamp\Rpc\PendingCallRegistry  $registry  The pending call tracking registry.
     */
    public function __construct(
        private readonly WampSession $session,
        private readonly PendingCallRegistry $registry,
    ) {
    }

    // -------------------------------------------------------------------------
    // CallerContract Implementation
    // -------------------------------------------------------------------------

    /**
     * Execute a synchronous RPC call — blocks until a response is received or timeout occurs.
     *
     * @param  string  $procedure  The URI of the procedure to invoke.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<string, mixed>  $kwargs  Keyword arguments.
     * @return mixed The invocation result.
     */
    public function call(string $procedure, array $args = [], array $kwargs = []): mixed
    {
        return $this->callAsync($procedure, $args, $kwargs)->await();
    }

    /**
     * Execute an asynchronous RPC call — returns an AMPHP Future resolving to the result.
     *
     * @param  string  $procedure  The URI of the procedure to invoke.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<string, mixed>  $kwargs  Keyword arguments.
     * @return Future<mixed> A future resolving when a RESULT or ERROR message arrives.
     */
    public function callAsync(string $procedure, array $args = [], array $kwargs = []): Future
    {
        // 1. Register the pending call in the tracking registry
        $pending = $this->registry->register($procedure);

        // 2. Transmit the CALL message to the WAMP router
        $this->session->send(
            MessageFactory::call(
                requestId: $pending->requestId,
                procedure: $procedure,
                args: $args,
                kwargs: $kwargs,
            ),
        );

        // 3. Return the Future — completes when RESULT or ERROR is processed
        return $pending->getFuture();
    }

    // -------------------------------------------------------------------------
    // Connection Loss
    // -------------------------------------------------------------------------

    /**
     * Reject every in-flight call, typically because the connection to the router was lost.
     *
     * @param  Throwable  $e  The reason the calls can no longer complete.
     */
    public function rejectAll(Throwable $e): void
    {
        $this->registry->rejectAll($e);
    }

    // -------------------------------------------------------------------------
    // Incoming Message Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming RESULT messages dispatched from the router.
     * Expected format: [50, requestId, details, args?, kwargs?]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming RESULT message.
     */
    public function onResult(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $args = $message->get(3) ?? [];
        $kwargs = $message->get(4) ?? [];

        try {
            $pending = $this->registry->pull($requestId);
        } catch (RpcException) {
            // Unknown request ID — silently ignore
            return;
        }

        // If keyword arguments exist, return those; otherwise unpack positional results
        $result = match (true) {
            !empty($kwargs) => $kwargs,
            count($args) === 1 => $args[0],
            count($args) > 1 => $args,
            default => null,
        };

        $pending->resolve($result);
    }

    /**
     * Handle incoming ERROR messages associated with a call request.
     * Expected format: [8, CALL, requestId, details, error, args?, kwargs?]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming ERROR message.
     */
    public function onError(WampMessage $message): void
    {
        $requestId = (int) $message->get(2);
        $wampError = (string) ($message->get(4) ?? 'wamp.error.unknown');
        $args = $message->get(5) ?? [];

        try {
            $pending = $this->registry->pull($requestId);
        } catch (RpcException) {
            return;
        }

        $pending->reject(new RpcException(
            message: "RPC call '{$pending->procedure}' failed with error: {$wampError}",
            wampError: $wampError,
            args: is_array($args) ? $args : [],
        ));
    }
}