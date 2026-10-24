<?php

use Hermod\LaravelWamp\Exceptions\PubSubException;
use Hermod\LaravelWamp\PubSub\PendingSubscriptionRegistry;
use Hermod\LaravelWamp\PubSub\Subscription;

describe('PendingSubscriptionRegistry', function () {

    beforeEach(function () {
        $this->registry = new PendingSubscriptionRegistry;
    });

    // -------------------------------------------------------------------------
    // Registrazione pendente
    // -------------------------------------------------------------------------

    describe('registerPending()', function () {

        it('registra una sottoscrizione pendente', function () {
            $handler = fn () => null;

            $this->registry->registerPending(42, 'com.myapp.test', $handler);

            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });

        it('riconosce topic pendente come già sottoscritto', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);

            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Conferma sottoscrizione
    // -------------------------------------------------------------------------

    describe('confirmSubscription()', function () {

        it('conferma la sottoscrizione e crea la Subscription', function () {
            $handler = fn () => null;

            $this->registry->registerPending(42, 'com.myapp.test', $handler);
            $subscription = $this->registry->confirmSubscription(42, 1001);

            expect($subscription)->toBeInstanceOf(Subscription::class)
                ->and($subscription->subscriptionId)->toBe(1001)
                ->and($subscription->topic)->toBe('com.myapp.test')
                ->and($subscription->handler)->toBe($handler);
        });

        it('lancia eccezione per requestId sconosciuto', function () {
            $this->registry->confirmSubscription(99999, 1001);
        })->throws(PubSubException::class, 'No pending subscription found');

        it('rimuove la sottoscrizione dai pending dopo la conferma', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);

            // Il topic è ora confermato, non più pendente
            // ma risulta ancora "sottoscritto"
            expect($this->registry->isTopicSubscribed('com.myapp.test'))->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // Ricerca
    // -------------------------------------------------------------------------

    describe('findBySubscriptionId()', function () {

        it('trova una subscription per subscriptionId', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 9999);

            $found = $this->registry->findBySubscriptionId(9999);

            expect($found)->toBeInstanceOf(Subscription::class)
                ->and($found->subscriptionId)->toBe(9999);
        });

        it('restituisce null per subscriptionId inesistente', function () {
            expect($this->registry->findBySubscriptionId(99999))->toBeNull();
        });
    });

    describe('findByTopic()', function () {

        it('trova una subscription per topic', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);

            $found = $this->registry->findByTopic('com.myapp.test');

            expect($found)->toBeInstanceOf(Subscription::class)
                ->and($found->topic)->toBe('com.myapp.test');
        });

        it('restituisce null per topic non sottoscritto', function () {
            expect($this->registry->findByTopic('com.myapp.inesistente'))->toBeNull();
        });
    });

    // -------------------------------------------------------------------------
    // Disiscrizione
    // -------------------------------------------------------------------------

    describe('unsubscribe()', function () {

        it('registra una disiscrizione pendente', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->registerPendingUnsubscribe(2, 'com.myapp.test');

            // Dopo unsubscribe pending il topic è ancora presente
            // finché non arriva la conferma
            expect($this->registry->findByTopic('com.myapp.test'))->not->toBeNull();
        });

        it('rimuove la sottoscrizione dopo conferma unsubscription', function () {
            $this->registry->registerPending(1, 'com.myapp.test', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->registerPendingUnsubscribe(2, 'com.myapp.test');
            $this->registry->confirmUnsubscription(2);

            expect($this->registry->findByTopic('com.myapp.test'))->toBeNull()
                ->and($this->registry->isTopicSubscribed('com.myapp.test'))->toBeFalse();
        });

        it('ignora silenziosamente requestId sconosciuto in confirmUnsubscription', function () {
            // Non deve lanciare eccezioni
            $this->registry->confirmUnsubscription(99999);
            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------------------------
    // getAll()
    // -------------------------------------------------------------------------

    describe('getAll()', function () {

        it('restituisce tutte le sottoscrizioni attive', function () {
            $this->registry->registerPending(1, 'com.myapp.uno', fn () => null);
            $this->registry->registerPending(2, 'com.myapp.due', fn () => null);
            $this->registry->confirmSubscription(1, 1001);
            $this->registry->confirmSubscription(2, 1002);

            $all = $this->registry->getAll();

            expect($all)
                ->toHaveCount(2)
                ->toHaveKeys(['com.myapp.uno', 'com.myapp.due']);
        });

        it('restituisce array vuoto se nessuna sottoscrizione attiva', function () {
            expect($this->registry->getAll())->toBe([]);
        });
    });
});
