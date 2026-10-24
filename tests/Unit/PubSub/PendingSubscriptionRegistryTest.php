<?php

use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\PubSub\PendingSubscriptionRegistry;
use Hermod\LaravelWamp\PubSub\Subscription;

describe('PendingSubscriptionRegistry', function () {

    beforeEach(function () {
        $this->registry = new PendingSubscriptionRegistry;
    });

    // -------------------------------------------------------------------------
    // Pending registration
    // -------------------------------------------------------------------------

    describe('registerPending()', function () {

        it('registers a pending subscription', function () {
            $handler = fn () => null;

            $this->registry->registerPending(42, 'com.myapp.test', $handler);

            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });

        it('treats a pending topic as already subscribed', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);

            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Subscription confirmation
    // -------------------------------------------------------------------------

    describe('confirmSubscription()', function () {

        it('confirms the subscription and creates the Subscription', function () {
            $handler = fn () => null;

            $this->registry->registerPending(42, 'com.myapp.test', $handler);
            $subscription = $this->registry->confirmSubscription(42, 1001);

            expect($subscription)->toBeInstanceOf(Subscription::class)
                ->and($subscription->subscriptionId)->toBe(1001)
                ->and($subscription->topic)->toBe('com.myapp.test')
                ->and($subscription->handler)->toBe($handler);
        });

        it('throws for an unknown requestId', function () {
            $this->registry->confirmSubscription(99999, 1001);
        })->throws(PubSubException::class, 'No pending subscription found');

        it('removes the subscription from pending after confirmation', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);

            // The topic is now confirmed, no longer pending,
            // but it is still reported as "subscribed"
            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    describe('findBySubscriptionId()', function () {

        it('finds a subscription by subscriptionId', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 9999);

            $found = $this->registry->findBySubscriptionId(9999);

            expect($found)->toBeInstanceOf(Subscription::class)
                ->and($found->subscriptionId)->toBe(9999);
        });

        it('returns null for an unknown subscriptionId', function () {
            expect($this->registry->findBySubscriptionId(99999))->toBeNull();
        });
    });

    describe('findByTopic()', function () {

        it('finds a subscription by topic', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);

            $found = $this->registry->findByTopic('com.myapp.test');

            expect($found)->toBeInstanceOf(Subscription::class)
                ->and($found->topic)->toBe('com.myapp.test');
        });

        it('returns null for a topic that is not subscribed', function () {
            expect($this->registry->findByTopic('com.myapp.inesistente'))->toBeNull();
        });
    });

    // -------------------------------------------------------------------------
    // Unsubscription
    // -------------------------------------------------------------------------

    describe('unsubscribe()', function () {

        it('registers a pending unsubscription', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->registerPendingUnsubscribe(2, 'com.myapp.test');

            // With a pending unsubscribe the topic is still present
            // until the confirmation arrives
            expect($this->registry->findByTopic('com.myapp.test'))->not->toBeNull();
        });

        it('removes the subscription after the unsubscription is confirmed', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->registerPendingUnsubscribe(2, 'com.myapp.test');
            $this->registry->confirmUnsubscription(2);

            expect($this->registry->findByTopic('com.myapp.test'))->toBeNull()
                ->and($this->registry->isTopicSubscribed('com.myapp.test'))->toBeFalse();
        });

        it('silently ignores an unknown requestId in confirmUnsubscription', function () {
            // Must not throw
            $this->registry->confirmUnsubscription(99999);
            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // getAll()
    // -------------------------------------------------------------------------

    describe('getAll()', function () {

        it('returns all active subscriptions', function () {
            $this->registry->registerPending(1, 'com.myapp.uno', fn () => null);
            $this->registry->registerPending(2, 'com.myapp.due', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->confirmSubscription(2, 1002);

            $all = $this->registry->getAll();

            expect($all)
                ->toHaveCount(2)
                ->toHaveKeys(['com.myapp.uno', 'com.myapp.due']);
        });

        it('returns an empty array when there are no active subscriptions', function () {
            expect($this->registry->getAll())->toBe([]);
        });
    });
});
