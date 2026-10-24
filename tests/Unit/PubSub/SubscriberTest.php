<?php

use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\Laravel\Events\WampEventReceived;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\PubSub\PendingSubscriptionRegistry;
use Hermod\LaravelWamp\PubSub\Subscriber;
use Hermod\LaravelWamp\PubSub\Subscription;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Session\WampSession;
use Hermod\LaravelWamp\Tests\TestCase;
use Illuminate\Support\Facades\Event;

uses(TestCase::class);

describe('Subscriber', function () {

    beforeEach(function () {
        $this->session = Mockery::mock(WampSession::class);
        $this->registry = new PendingSubscriptionRegistry;
        $this->subscriber = new Subscriber(
            $this->session,
            new RequestIdGenerator,
            $this->registry,
        );
    });

    afterEach(fn () => Mockery::close());

    // -------------------------------------------------------------------------
    // subscribe()
    // -------------------------------------------------------------------------

    describe('subscribe()', function () {

        it('sends SUBSCRIBE to the router', function () {
            $this->session
                ->shouldReceive('send')
                ->once()
                ->withArgs(function (WampMessage $message) {
                    return $message->type() === MessageType::SUBSCRIBE
                        && $message->get(3) === 'com.myapp.notifiche';
                });

            $this->subscriber->subscribe('com.myapp.notifiche', fn () => null);
        });

        it('returns a Subscription object', function () {
            $this->session->shouldReceive('send')->once();

            $subscription = $this->subscriber->subscribe('com.myapp.notifiche', fn () => null);

            expect($subscription)->toBeInstanceOf(Subscription::class)
                ->and($subscription->topic)->toBe('com.myapp.notifiche');
        });

        it('throws when already subscribed to the same topic', function () {
            $this->session->shouldReceive('send')->once();

            $this->subscriber->subscribe('com.myapp.notifiche', fn () => null);

            expect(fn () => $this->subscriber->subscribe('com.myapp.notifiche', fn () => null))
                ->toThrow(PubSubException::class, 'Already subscribed');
        });
    });

    // -------------------------------------------------------------------------
    // onSubscribed()
    // -------------------------------------------------------------------------

    describe('onSubscribed()', function () {

        it('confirms the subscription when SUBSCRIBED arrives', function () {
            $this->session->shouldReceive('send')->once();

            $this->subscriber->subscribe('com.myapp.test', fn () => null);

            // Retrieve the requestId
            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            // Simulate SUBSCRIBED [33, requestId, subscriptionId]
            $subscribed = WampMessage::fromArray([33, $requestId, 7777]);
            $this->subscriber->onSubscribed($subscribed);

            $found = $this->registry->findBySubscriptionId(7777);
            expect($found)->toBeInstanceOf(Subscription::class)
                ->and($found->subscriptionId)->toBe(7777)
                ->and($found->topic)->toBe('com.myapp.test');
        });

        it('silently ignores SUBSCRIBED for an unknown requestId', function () {
            $unknown = WampMessage::fromArray([33, 99999, 1234]);
            $this->subscriber->onSubscribed($unknown);

            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // onEvent()
    // -------------------------------------------------------------------------

    describe('onEvent()', function () {

        it('runs the handler when an EVENT arrives', function () {
            $this->session->shouldReceive('send')->once();

            $received = [];
            $this->subscriber->subscribe('com.myapp.test', function (array $args) use (&$received) {
                $received = $args;
            });

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            $subscribed = WampMessage::fromArray([33, $requestId, 8888]);
            $this->subscriber->onSubscribed($subscribed);

            // Simulate EVENT [36, subscriptionId, publicationId, {}, args]
            $event = WampMessage::fromArray([36, 8888, 1111, [], ['ciao', 'mondo']]);
            $this->subscriber->onEvent($event);

            expect($received)->toBe(['ciao', 'mondo']);
        });

        it('dispatches a WampEventReceived Laravel event', function () {
            Event::fake();

            $this->session->shouldReceive('send')->once();

            $this->subscriber->subscribe('com.myapp.test', fn () => null);

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            $subscribed = WampMessage::fromArray([33, $requestId, 9999]);
            $this->subscriber->onSubscribed($subscribed);

            $event = WampMessage::fromArray([36, 9999, 2222, [], ['dato' => 'valore']]);
            $this->subscriber->onEvent($event);

            Event::assertDispatched(WampEventReceived::class, function (WampEventReceived $e) {
                return $e->topic === 'com.myapp.test'
                    && $e->subscriptionId === 9999
                    && $e->publicationId === 2222
                    && $e->args === ['dato' => 'valore'];
            });
        });

        it('ignores EVENT for an unknown subscriptionId', function () {
            $unknown = WampMessage::fromArray([36, 99999, 1111, [], []]);
            $this->subscriber->onEvent($unknown);

            expect(true)->toBeTrue();
        });

        it('does not break the loop when the handler throws', function () {
            $this->session->shouldReceive('send')->once();

            $this->subscriber->subscribe('com.myapp.test', function () {
                throw new RuntimeException('Handler fallito');
            });

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            $subscribed = WampMessage::fromArray([33, $requestId, 1234]);
            $this->subscriber->onSubscribed($subscribed);

            // The exception must not propagate
            $event = WampMessage::fromArray([36, 1234, 5678, [], []]);
            $this->subscriber->onEvent($event);

            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // unsubscribe()
    // -------------------------------------------------------------------------

    describe('unsubscribe()', function () {

        it('sends UNSUBSCRIBE to the router', function () {
            $this->session->shouldReceive('send')->twice(); // SUBSCRIBE + UNSUBSCRIBE

            $this->subscriber->subscribe('com.myapp.test', fn () => null);

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            $subscribed = WampMessage::fromArray([33, $requestId, 4444]);
            $this->subscriber->onSubscribed($subscribed);

            $this->session
                ->shouldReceive('send')
                ->withArgs(function (WampMessage $message) {
                    return $message->type() === MessageType::UNSUBSCRIBE;
                });

            $this->subscriber->unsubscribe('com.myapp.test');
        });

        it('throws when the topic is not subscribed', function () {
            expect(fn () => $this->subscriber->unsubscribe('com.myapp.inesistente'))
                ->toThrow(PubSubException::class, 'No active subscription found');
        });
    });

    // -------------------------------------------------------------------------
    // onUnsubscribed()
    // -------------------------------------------------------------------------

    describe('onUnsubscribed()', function () {

        it('removes the subscription when UNSUBSCRIBED arrives', function () {
            $this->session->shouldReceive('send')->twice();

            $this->subscriber->subscribe('com.myapp.test', fn () => null);

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);
            $requestId = array_key_first($pending);

            $subscribed = WampMessage::fromArray([33, $requestId, 5555]);
            $this->subscriber->onSubscribed($subscribed);

            $this->subscriber->unsubscribe('com.myapp.test');

            $pendingUnsub = (new ReflectionProperty($this->registry, 'pendingUnsubscribes'))
                ->getValue($this->registry);
            $unsubRequestId = array_key_first($pendingUnsub);

            // Simulate UNSUBSCRIBED [35, requestId]
            $unsubscribed = WampMessage::fromArray([35, $unsubRequestId]);
            $this->subscriber->onUnsubscribed($unsubscribed);

            expect($this->registry->findByTopic('com.myapp.test'))->toBeNull()
                ->and($this->registry->isTopicSubscribed('com.myapp.test'))->toBeFalse();
        });
    });

    // -------------------------------------------------------------------------
    // getSubscriptions()
    // -------------------------------------------------------------------------

    describe('getSubscriptions()', function () {

        it('returns all active subscriptions', function () {
            $this->session->shouldReceive('send')->twice();

            $this->subscriber->subscribe('com.myapp.uno', fn () => null);
            $this->subscriber->subscribe('com.myapp.due', fn () => null);

            $pending = (new ReflectionProperty($this->registry, 'pending'))
                ->getValue($this->registry);

            $ids = array_keys($pending);

            $this->subscriber->onSubscribed(WampMessage::fromArray([33, $ids[0], 101]));
            $this->subscriber->onSubscribed(WampMessage::fromArray([33, $ids[1], 102]));

            $subscriptions = $this->subscriber->getSubscriptions();

            expect($subscriptions)
                ->toHaveCount(2)
                ->toHaveKeys(['com.myapp.uno', 'com.myapp.due']);
        });
    });
});
