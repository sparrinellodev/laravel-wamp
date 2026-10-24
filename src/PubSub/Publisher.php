<?php

namespace Hermod\LaravelWamp\PubSub;

use Amp\DeferredFuture;
use Amp\Future;
use Hermod\LaravelWamp\Contracts\PublisherContract;
use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Session\WampSession;
use Throwable;

/**
 * Handles publishing WAMP events to topics with optional acknowledgement support.
 *
 * Implements the PublisherContract interface, managing unacknowledged fire-and-forget 
 * broadcasts as well as asynchronous acknowledged publications using AMPHP futures 
 * and deferred futures.
 */
class Publisher implements PublisherContract
{
    /** @var array<int, DeferredFuture> requestId → Deferred instance for publishWithAck */
    private array $pendingAcks = [];

    /**
     * Create a new Publisher instance.
     *
     * @param  \Hermod\LaravelWamp\Session\WampSession  $session  The active WAMP session handler.
     * @param  \Hermod\LaravelWamp\Rpc\RequestIdGenerator  $idGenerator  The request ID generator service.
     */
    public function __construct(
        private readonly WampSession $session,
        private readonly RequestIdGenerator $idGenerator,
    ) {
    }

    // -------------------------------------------------------------------------
    // PublisherContract Implementation
    // -------------------------------------------------------------------------

    /**
     * Broadcast an event to a topic without requesting an acknowledgement.
     *
     * @param  string  $topic  The URI of the topic to publish to.
     * @param  array<mixed>  $args  Positional arguments for the event payload.
     * @param  array<string, mixed>  $kwargs  Keyword arguments for the event payload.
     */
    public function publish(string $topic, array $args = [], array $kwargs = []): void
    {
        $requestId = $this->idGenerator->generate();

        $this->session->send(
            MessageFactory::publish(
                requestId: $requestId,
                topic: $topic,
                args: $args,
                kwargs: $kwargs,
                acknowledge: false,
            ),
        );
    }

    /**
     * Broadcast an event to a topic and return an asynchronous Future for the acknowledgement.
     *
     * @param  string  $topic  The URI of the topic to publish to.
     * @param  array<mixed>  $args  Positional arguments for the event payload.
     * @param  array<string, mixed>  $kwargs  Keyword arguments for the event payload.
     * @return Future<int> A future resolving to the router-assigned publication ID.
     */
    public function publishWithAck(string $topic, array $args = [], array $kwargs = []): Future
    {
        $requestId = $this->idGenerator->generate();
        $deferred = new DeferredFuture;

        $this->pendingAcks[$requestId] = $deferred;

        $this->session->send(
            MessageFactory::publish(
                requestId: $requestId,
                topic: $topic,
                args: $args,
                kwargs: $kwargs,
                acknowledge: true,
            ),
        );

        return $deferred->getFuture();
    }

    // -------------------------------------------------------------------------
    // Connection Loss
    // -------------------------------------------------------------------------

    /**
     * Fail every acknowledgement still awaited, typically because the connection to the router was lost.
     *
     * @param  Throwable  $e  The reason the publications can no longer be acknowledged.
     */
    public function rejectAll(Throwable $e): void
    {
        foreach ($this->pendingAcks as $deferred) {
            // The publisher may never await this future: don't report the error to the event loop
            $deferred->getFuture()->ignore();
            $deferred->error($e);
        }

        $this->pendingAcks = [];
    }

    // -------------------------------------------------------------------------
    // Incoming Message Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming PUBLISHED messages dispatched from the router.
     * Expected format: [17, requestId, publicationId]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming PUBLISHED message.
     */
    public function onPublished(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $publicationId = (int) $message->get(2);

        if (!isset($this->pendingAcks[$requestId])) {
            return;
        }

        $deferred = $this->pendingAcks[$requestId];
        unset($this->pendingAcks[$requestId]);

        $deferred->complete($publicationId);
    }

    /**
     * Handle incoming ERROR messages associated with a publish request.
     * Expected format: [8, PUBLISH, requestId, details, error]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming ERROR message.
     */
    public function onError(WampMessage $message): void
    {
        $requestId = (int) $message->get(2);
        $wampError = (string) ($message->get(4) ?? 'wamp.error.unknown');

        if (!isset($this->pendingAcks[$requestId])) {
            return;
        }

        $deferred = $this->pendingAcks[$requestId];
        unset($this->pendingAcks[$requestId]);

        $deferred->error(new PubSubException(
            "Publication failed with WAMP error '{$wampError}'",
        ));
    }
}