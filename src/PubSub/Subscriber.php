<?php

namespace Hermod\LaravelWamp\PubSub;

use Hermod\LaravelWamp\Contracts\SubscriberContract;
use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\Laravel\Events\WampEventReceived;
use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Session\WampSession;
use Illuminate\Support\Facades\Event;

/**
 * Manages WAMP topic subscriptions, unsubscriptions, and inbound event dispatches.
 *
 * Implements the SubscriberContract interface, coordinating subscription lifecycles 
 * through a registry, dispatching local callback handlers, and firing Laravel domain events.
 */
class Subscriber implements SubscriberContract
{
    /**
     * Create a new Subscriber instance.
     *
     * @param  \Hermod\LaravelWamp\Session\WampSession  $session  The active WAMP session handler.
     * @param  \Hermod\LaravelWamp\Rpc\RequestIdGenerator  $idGenerator  The request ID generator service.
     * @param  \Hermod\LaravelWamp\PubSub\PendingSubscriptionRegistry  $registry  The subscription tracking registry.
     */
    public function __construct(
        private readonly WampSession $session,
        private readonly RequestIdGenerator $idGenerator,
        private readonly PendingSubscriptionRegistry $registry,
    ) {
    }

    // -------------------------------------------------------------------------
    // SubscriberContract Implementation
    // -------------------------------------------------------------------------

    /**
     * Subscribe to a WAMP topic with a callback handler.
     *
     * @param  string  $topic  The URI of the topic to subscribe to.
     * @param  callable  $handler  The callback invoked when events are received on the topic.
     * @return Subscription A placeholder subscription object completed upon router confirmation.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\PubSubException If already subscribed to the topic.
     */
    public function subscribe(string $topic, callable $handler): Subscription
    {
        if ($this->registry->isTopicSubscribed($topic)) {
            throw new PubSubException(
                "Already subscribed to topic '{$topic}'.",
            );
        }

        $requestId = $this->idGenerator->generate();

        $this->registry->registerPending($requestId, $topic, $handler);

        $this->session->send(
            MessageFactory::subscribe($requestId, $topic),
        );

        // The actual Subscription instance is created in onSubscribed() 
        // when confirmation arrives from the router. 
        // We return a placeholder instance that gets updated later.
        return new Subscription(
            subscriptionId: 0,  // Will be updated in onSubscribed
            topic: $topic,
            handler: $handler,
        );
    }

    /**
     * Unsubscribe from a topic by its URI.
     *
     * @param  string  $topic  The URI of the topic.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\PubSubException If no active subscription is found for the topic.
     */
    public function unsubscribe(string $topic): void
    {
        $subscription = $this->registry->findByTopic($topic);

        if ($subscription === null) {
            throw new PubSubException(
                "No active subscription found for topic '{$topic}'.",
            );
        }

        $this->unsubscribeById($subscription);
    }

    /**
     * Unsubscribe from a topic using a Subscription instance.
     *
     * @param  \Hermod\LaravelWamp\PubSub\Subscription  $subscription  The subscription to remove.
     */
    public function unsubscribeById(Subscription $subscription): void
    {
        $requestId = $this->idGenerator->generate();

        $this->registry->registerPendingUnsubscribe($requestId, $subscription->topic);

        $this->session->send(
            MessageFactory::unsubscribe($requestId, $subscription->subscriptionId),
        );
    }

    /**
     * Get all active subscriptions.
     *
     * @return array<string, Subscription> Array of active subscriptions.
     */
    public function getSubscriptions(): array
    {
        return $this->registry->getAll();
    }

    // -------------------------------------------------------------------------
    // Session Recovery
    // -------------------------------------------------------------------------

    /**
     * Forget every subscription, active or pending, and return their handlers.
     *
     * Used after the connection is lost: the router has already dropped the old
     * session's subscriptions, so they must be subscribed again on a new session.
     *
     * @return array<string, callable> Topic URI → handler.
     */
    public function flush(): array
    {
        return $this->registry->flush();
    }

    // -------------------------------------------------------------------------
    // Incoming Message Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming SUBSCRIBED messages dispatched from the router.
     * Expected format: [33, requestId, subscriptionId]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming SUBSCRIBED message.
     */
    public function onSubscribed(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $subscriptionId = (int) $message->get(2);

        try {
            $this->registry->confirmSubscription($requestId, $subscriptionId);
        } catch (PubSubException) {
            // Unknown request ID — ignore
        }
    }

    /**
     * Handle incoming UNSUBSCRIBED messages dispatched from the router.
     * Expected format: [35, requestId]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming UNSUBSCRIBED message.
     */
    public function onUnsubscribed(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $this->registry->confirmUnsubscription($requestId);
    }

    /**
     * Handle incoming EVENT messages dispatched from the router.
     * Expected format: [36, subscriptionId, publicationId, details, args?, kwargs?]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming EVENT message.
     */
    public function onEvent(WampMessage $message): void
    {
        $subscriptionId = (int) $message->get(1);
        $publicationId = (int) $message->get(2);
        $details = $message->get(3) ?? [];
        $args = $message->get(4) ?? [];
        $kwargs = $message->get(5) ?? [];

        $subscription = $this->registry->findBySubscriptionId($subscriptionId);

        if ($subscription === null) {
            return; // Unknown subscription ID — ignore
        }

        // 1. Execute the registered subscription callback handler
        try {
            ($subscription->handler)(
                is_array($args) ? $args : [],
                is_array($kwargs) ? $kwargs : [],
                is_array($details) ? $details : [],
            );
        } catch (\Throwable) {
            // The handler must not block the event loop — suppress exceptions
        }

        // 2. Dispatch a Laravel framework event 
        //    allowing the application to react via event listeners without explicit handlers
        try {
            Event::dispatch(new WampEventReceived(
                topic: $subscription->topic,
                subscriptionId: $subscriptionId,
                publicationId: $publicationId,
                args: is_array($args) ? $args : [],
                kwargs: is_array($kwargs) ? $kwargs : [],
                details: is_array($details) ? $details : [],
            ));
        } catch (\Throwable) {
            // Suppress Laravel event dispatch errors
        }
    }
}