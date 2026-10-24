<?php

namespace Hermod\LaravelWamp\Client;

use Amp\Future;
use Hermod\LaravelWamp\Contracts\WampClientContract;
use Hermod\LaravelWamp\Exceptions\ReconnectException;
use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Exceptions\WampClientException;
use Hermod\LaravelWamp\Exceptions\WampProtocolException;
use Hermod\LaravelWamp\PubSub\Publisher;
use Hermod\LaravelWamp\PubSub\Subscriber;
use Hermod\LaravelWamp\PubSub\Subscription;
use Hermod\LaravelWamp\Reconnect\ReconnectManager;
use Hermod\LaravelWamp\Rpc\Callee;
use Hermod\LaravelWamp\Rpc\Caller;
use Hermod\LaravelWamp\Rpc\MessageDispatcher;
use Hermod\LaravelWamp\Session\WampSession;
use Throwable;

/**
 * The primary WAMP client orchestrator.
 *
 * This class ties together connection management, session handling, 
 * RPC (Caller/Callee), and Pub/Sub (Publisher/Subscriber) features, 
 * acting as the main entry point for WAMP operations.
 */
class WampClient implements WampClientContract
{
    /**
     * Indicates whether the client is actively listening for incoming messages.
     */
    private bool $listening = false;

    /**
     * Create a new WampClient instance.
     *
     * @param WampSession $session The underlying WAMP session manager.
     * @param Caller $caller Handles outgoing RPC calls.
     * @param Callee $callee Handles incoming RPC registrations and invocations.
     * @param Publisher $publisher Handles outgoing topic publications.
     * @param Subscriber $subscriber Handles topic subscriptions.
     * @param MessageDispatcher $dispatcher Routes incoming WAMP messages to their respective handlers.
     * @param ReconnectManager $reconnectManager Manages automatic reconnection and state recovery.
     */
    public function __construct(
        private readonly WampSession $session,
        private readonly Caller $caller,
        private readonly Callee $callee,
        private readonly Publisher $publisher,
        private readonly Subscriber $subscriber,
        private readonly MessageDispatcher $dispatcher,
        private readonly ReconnectManager $reconnectManager,
    ) {
    }

    // -------------------------------------------------------------------------
    // Connection Management
    // -------------------------------------------------------------------------

    /**
     * Establish the connection to the WAMP router.
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $this->session->hello();
    }

    /**
     * Gracefully disconnect from the WAMP router.
     */
    public function disconnect(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $this->listening = false;

        try {
            $this->session->goodbye();
        } catch (Throwable) {
            // Silently catch exceptions during disconnect to ensure local cleanup
        }
    }

    /**
     * Determine if the client is currently connected and the session is established.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->session->isEstablished();
    }

    // -------------------------------------------------------------------------
    // Caller Contract (RPC)
    // -------------------------------------------------------------------------

    /**
     * Synchronously call a remote procedure.
     * This method will block until the result is returned by the router.
     *
     * @param  string  $procedure  The URI of the procedure to call.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<mixed>  $kwargs  Keyword arguments.
     * @return mixed The result of the RPC call.
     *
     * @throws WampClientException If the connection is lost while waiting for the result.
     */
    public function call(string $procedure, array $args = [], array $kwargs = []): mixed
    {
        $this->ensureConnected();

        $future = $this->caller->callAsync($procedure, $args, $kwargs);

        while (!$future->isComplete()) {
            try {
                $message = $this->session->receive();
                $this->dispatcher->dispatch($message);
            } catch (TransportException $e) {
                $this->failPendingRequests($e);

                throw new WampClientException(
                    "Connection lost while waiting for the RPC result: {$e->getMessage()}",
                    previous: $e,
                );
            }
        }

        return $future->await();
    }

    /**
     * Asynchronously call a remote procedure.
     * Returns an Amp Future that resolves when the result is available.
     *
     * @param  string  $procedure  The URI of the procedure to call.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<mixed>  $kwargs  Keyword arguments.
     * @return Future<mixed>
     */
    public function callAsync(string $procedure, array $args = [], array $kwargs = []): Future
    {
        $this->ensureConnected();

        return \Amp\async(function () use ($procedure, $args, $kwargs): mixed {
            $future = $this->caller->callAsync($procedure, $args, $kwargs);

            while (!$future->isComplete()) {
                try {
                    $message = $this->session->receive();
                    $this->dispatcher->dispatch($message);
                } catch (TransportException $e) {
                    $this->failPendingRequests($e);

                    throw new WampClientException(
                        "Connection lost while waiting for the async RPC result: {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }

            return $future->await();
        });
    }

    // -------------------------------------------------------------------------
    // Callee Contract (RPC)
    // -------------------------------------------------------------------------

    /**
     * Register a callable to handle a remote procedure call.
     *
     * @param  string  $procedure  The URI of the procedure to register.
     * @param  callable  $handler  The function/method to execute when called.
     */
    public function register(string $procedure, callable $handler): void
    {
        $this->ensureConnected();
        $this->callee->register($procedure, $handler);
    }

    /**
     * Unregister a previously registered procedure.
     *
     * @param  string  $procedure  The URI of the procedure.
     */
    public function unregister(string $procedure): void
    {
        $this->ensureConnected();
        $this->callee->unregister($procedure);
    }

    /**
     * Get all active RPC registrations.
     *
     * @return array<string, callable>
     */
    public function getRegistrations(): array
    {
        return $this->callee->getRegistrations();
    }

    // -------------------------------------------------------------------------
    // Publisher Contract (Pub/Sub)
    // -------------------------------------------------------------------------

    /**
     * Publish an event to a topic (fire-and-forget).
     *
     * @param  string  $topic  The URI of the topic.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<mixed>  $kwargs  Keyword arguments.
     */
    public function publish(string $topic, array $args = [], array $kwargs = []): void
    {
        $this->ensureConnected();
        $this->publisher->publish($topic, $args, $kwargs);
    }

    /**
     * Publish an event to a topic and wait for acknowledgment from the router.
     * Returns an Amp Future that resolves to the Publication ID.
     *
     * @param  string  $topic  The URI of the topic.
     * @param  array<mixed>  $args  Positional arguments.
     * @param  array<mixed>  $kwargs  Keyword arguments.
     * @return Future<int> The publication ID.
     */
    public function publishWithAck(string $topic, array $args = [], array $kwargs = []): Future
    {
        $this->ensureConnected();

        return \Amp\async(function () use ($topic, $args, $kwargs): int {
            $future = $this->publisher->publishWithAck($topic, $args, $kwargs);

            while (!$future->isComplete()) {
                try {
                    $message = $this->session->receive();
                    $this->dispatcher->dispatch($message);
                } catch (TransportException $e) {
                    $this->failPendingRequests($e);

                    throw new WampClientException(
                        "Connection lost while waiting for PUBLISHED acknowledgment: {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }

            return $future->await();
        });
    }

    // -------------------------------------------------------------------------
    // Subscriber Contract (Pub/Sub)
    // -------------------------------------------------------------------------

    /**
     * Subscribe to a topic to receive events.
     *
     * @param  string  $topic  The URI of the topic.
     * @param  callable  $handler  The function to execute when an event is received.
     * @return Subscription The active subscription instance.
     */
    public function subscribe(string $topic, callable $handler): Subscription
    {
        $this->ensureConnected();

        return $this->subscriber->subscribe($topic, $handler);
    }

    /**
     * Unsubscribe from a topic using its URI.
     *
     * @param  string  $topic  The URI of the topic.
     */
    public function unsubscribe(string $topic): void
    {
        $this->ensureConnected();
        $this->subscriber->unsubscribe($topic);
    }

    /**
     * Unsubscribe using a specific Subscription instance.
     *
     * @param  Subscription  $subscription  The subscription to remove.
     */
    public function unsubscribeById(Subscription $subscription): void
    {
        $this->ensureConnected();
        $this->subscriber->unsubscribeById($subscription);
    }

    /**
     * Get all active topic subscriptions.
     *
     * @return array<string, Subscription>
     */
    public function getSubscriptions(): array
    {
        return $this->subscriber->getSubscriptions();
    }

    // -------------------------------------------------------------------------
    // Message Loop
    // -------------------------------------------------------------------------

    /**
     * Start the continuous message reception loop.
     * * This method blocks and keeps listening for incoming WAMP messages 
     * (RPC calls, Pub/Sub events) as long as the connection is active.
     *
     * @throws WampClientException If a fatal error occurs in the loop.
     */
    public function listen(): void
    {
        $this->ensureConnected();
        $this->listening = true;

        while ($this->listening && $this->isConnected()) {
            try {
                $message = $this->session->receive();
                $this->dispatcher->dispatch($message);
            } catch (WampProtocolException) {
                // GOODBYE received from the router — clean exit
                $this->listening = false;
                return;
            } catch (TransportException $e) {
                // Connection lost — fail in-flight requests, then reconnect if enabled
                $this->failPendingRequests($e);

                if (! $this->reconnectManager->isEnabled()) {
                    $this->listening = false;

                    throw new WampClientException(
                        "Connection lost: {$e->getMessage()}",
                        previous: $e,
                    );
                }

                try {
                    $this->handleReconnect();
                } catch (ReconnectException $re) {
                    $this->listening = false;

                    throw new WampClientException(
                        "Connection lost and automatic reconnection failed: {$re->getMessage()}",
                        previous: $re,
                    );
                }
            } catch (WampClientException $e) {
                $this->listening = false;
                throw $e;
            } catch (Throwable $e) {
                $this->listening = false;
                throw new WampClientException(
                    "Error occurred in the receive loop: {$e->getMessage()}",
                    previous: $e,
                );
            }
        }
    }

    /**
     * Process a single incoming message without blocking continuously.
     * Useful for manual event loop control or periodic polling.
     */
    public function tick(): void
    {
        $this->ensureConnected();

        $message = $this->session->receive();
        $this->dispatcher->dispatch($message);
    }

    // -------------------------------------------------------------------------
    // Session Information
    // -------------------------------------------------------------------------

    /**
     * Get the current WAMP session ID.
     *
     * @return int|null
     */
    public function getSessionId(): ?int
    {
        return $this->session->getSessionId();
    }

    /**
     * Get the connected routing realm.
     *
     * @return string
     */
    public function getRealm(): string
    {
        return $this->session->getRealm();
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the client has an active connection before proceeding.
     *
     * @throws WampClientException
     */
    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            throw new WampClientException(
                'Client is not connected. Call connect() before performing operations.'
            );
        }
    }

    /**
     * Handle the reconnection flow and restore the client's state.
     *
     * The router has already dropped the old session's registrations and subscriptions,
     * so they are taken out of the local state and registered again once a new session
     * is established. A failed attempt (handshake or restoration) is retried by the
     * ReconnectManager.
     *
     * @throws ReconnectException If every reconnection attempt fails.
     */
    private function handleReconnect(): void
    {
        $registrations = $this->callee->flush();
        $subscriptions = $this->subscriber->flush();

        $this->reconnectManager->reconnect(
            connectFn: function (): void {
                // A previous attempt may have failed after the handshake: start from a closed session
                if ($this->session->isEstablished()) {
                    $this->session->goodbye();
                }

                $this->session->hello();
            },
            onSuccess: function () use ($registrations, $subscriptions): void {
                // Discard any partial state left by a previous failed attempt
                $this->callee->flush();
                $this->subscriber->flush();

                // Restore all RPC registrations
                foreach ($registrations as $procedure => $handler) {
                    $this->callee->register($procedure, $handler);
                }

                // Restore all Pub/Sub subscriptions
                foreach ($subscriptions as $topic => $handler) {
                    $this->subscriber->subscribe($topic, $handler);
                }
            },
        );
    }

    /**
     * Reject every in-flight call and publication acknowledgement after the connection is lost.
     *
     * @param  TransportException  $e  The transport failure that ended the session.
     */
    private function failPendingRequests(TransportException $e): void
    {
        $error = new WampClientException(
            "Connection lost: {$e->getMessage()}",
            previous: $e,
        );

        $this->caller->rejectAll($error);
        $this->publisher->rejectAll($error);
    }
}