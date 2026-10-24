<?php

use Hermod\LaravelWamp\PubSub\Subscription;

describe('Subscription', function () {

    it('stores the subscription data', function () {
        $handler = fn () => null;

        $subscription = new Subscription(
            subscriptionId: 1001,
            topic: 'com.myapp.notifiche',
            handler: $handler,
        );

        expect($subscription->subscriptionId)->toBe(1001)
            ->and($subscription->topic)->toBe('com.myapp.notifiche')
            ->and($subscription->handler)->toBe($handler);
    });

    it('accepts any callable as handler', function () {
        $closure = fn ($args) => $args;
        $invokable = new class
        {
            public function __invoke() {}
        };

        $s1 = new Subscription(1, 'com.test.uno', $closure);
        $s2 = new Subscription(2, 'com.test.due', $invokable);

        expect($s1->handler)->toBe($closure)
            ->and($s2->handler)->toBe($invokable);
    });
});
