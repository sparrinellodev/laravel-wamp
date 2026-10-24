<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Client\WampClient;
use Hermod\LaravelWamp\Contracts\TransportContract;
use Hermod\LaravelWamp\Exceptions\ReconnectException;
use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Exceptions\WampClientException;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\PubSub\PendingSubscriptionRegistry;
use Hermod\LaravelWamp\PubSub\Publisher;
use Hermod\LaravelWamp\PubSub\Subscriber;
use Hermod\LaravelWamp\Reconnect\ExponentialBackoffStrategy;
use Hermod\LaravelWamp\Reconnect\ReconnectManager;
use Hermod\LaravelWamp\Rpc\Callee;
use Hermod\LaravelWamp\Rpc\Caller;
use Hermod\LaravelWamp\Rpc\MessageDispatcher;
use Hermod\LaravelWamp\Rpc\PendingCallRegistry;
use Hermod\LaravelWamp\Rpc\RequestIdGenerator;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Session\WampSession;

/**
 * In-memory transport that plays the router from a script of inbound frames.
 *
 * Each inbox entry is a WAMP message array, a Closure that builds one from the
 * messages sent so far, or a Throwable that simulates a transport failure.
 */
class ScriptedTransport implements TransportContract
{
    /** @var list<array<mixed>> */
    public array $sent = [];

    public int $connects = 0;

    private bool $connected = false;

    /** @param  list<array<mixed>|Closure|Throwable>  $inbox */
    public function __construct(private array $inbox) {}

    public function connect(): void
    {
        $this->connects++;
        $this->connected = true;
    }

    public function send(string $data): void
    {
        $this->sent[] = json_decode($data, true);
    }

    public function receive(): string
    {
        $next = array_shift($this->inbox) ?? throw new LogicException('The scripted inbox is empty.');

        if ($next instanceof Throwable) {
            $this->connected = false;

            throw $next;
        }

        return json_encode($next instanceof Closure ? $next($this->sent) : $next);
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /** @return list<array<mixed>> */
    public function sentOfType(MessageType $type): array
    {
        return array_values(array_filter($this->sent, fn (array $message) => $message[0] === $type->value));
    }
}

/**
 * Build a router reply to the most recent request of the given type, e.g. REGISTERED for REGISTER.
 */
function replyToLast(MessageType $request, MessageType $reply, int $id): Closure
{
    return function (array $sent) use ($request, $reply, $id): array {
        $requests = array_filter($sent, fn (array $message) => $message[0] === $request->value);

        return [$reply->value, end($requests)[1], $id];
    };
}

/**
 * Wire a WampClient the same way WampClientFactory does, on top of the given transport.
 */
function clientOver(TransportContract $transport, int $maxAttempts = 3, bool $reconnect = true): WampClient
{
    $session = new WampSession($transport, new JsonSerializer, 'realm1', new AnonymousAuthenticator);
    $ids = new RequestIdGenerator;
    $caller = new Caller($session, new PendingCallRegistry($ids));
    $callee = new Callee($session, $ids);
    $publisher = new Publisher($session, $ids);
    $subscriber = new Subscriber($session, $ids, new PendingSubscriptionRegistry);

    return new WampClient(
        session: $session,
        caller: $caller,
        callee: $callee,
        publisher: $publisher,
        subscriber: $subscriber,
        dispatcher: new MessageDispatcher($session, $caller, $callee, $publisher, $subscriber),
        reconnectManager: new ReconnectManager(
            strategy: new ExponentialBackoffStrategy($maxAttempts, 0.001, 0.01, 2.0),
            enabled: $reconnect,
        ),
    );
}

describe('WampClient — reconnect', function () {

    it('restores registrations and subscriptions after the connection drops', function () {
        $transport = new ScriptedTransport([
            [2, 1001, []], // WELCOME
            replyToLast(MessageType::REGISTER, MessageType::REGISTERED, 501),
            replyToLast(MessageType::SUBSCRIBE, MessageType::SUBSCRIBED, 601),
            new TransportException('Connection reset by peer'),
            [2, 2002, []], // WELCOME for the new session
            replyToLast(MessageType::REGISTER, MessageType::REGISTERED, 502),
            replyToLast(MessageType::SUBSCRIBE, MessageType::SUBSCRIBED, 602),
            [6, [], 'wamp.close.system_shutdown'], // router GOODBYE ends listen()
        ]);
        $client = clientOver($transport);

        $client->connect();
        $client->register('com.myapp.sum', fn (array $args) => array_sum($args));
        $client->subscribe('com.myapp.events', fn () => null);
        $client->listen();

        expect($transport->connects)->toBe(2)
            ->and($client->getSessionId())->toBe(2002)
            ->and($transport->sentOfType(MessageType::REGISTER))->toHaveCount(2)
            ->and($transport->sentOfType(MessageType::SUBSCRIBE))->toHaveCount(2)
            ->and($client->getRegistrations())->toHaveKey('com.myapp.sum')
            ->and($client->getSubscriptions())->toHaveKey('com.myapp.events');
    });

    it('retries when the handshake fails during reconnection', function () {
        $transport = new ScriptedTransport([
            [2, 1001, []],
            new TransportException('Connection reset by peer'),
            new TransportException('Connection reset by peer'), // first attempt: handshake fails
            [2, 3003, []], // second attempt: WELCOME
            [6, [], 'wamp.close.system_shutdown'],
        ]);
        $client = clientOver($transport);

        $client->connect();
        $client->listen();

        expect($transport->connects)->toBe(3)
            ->and($client->getSessionId())->toBe(3003);
    });

    it('throws WampClientException once reconnection attempts are exhausted', function () {
        $transport = new ScriptedTransport([
            [2, 1001, []],
            new TransportException('Connection reset by peer'),
            new TransportException('Router unreachable'),
            new TransportException('Router unreachable'),
        ]);
        $client = clientOver($transport, maxAttempts: 2);

        $client->connect();

        expect(fn () => $client->listen())
            ->toThrow(function (WampClientException $e) {
                expect($e->getMessage())->toContain('reconnection failed')
                    ->and($e->getPrevious())->toBeInstanceOf(ReconnectException::class);
            });

        expect($client->isConnected())->toBeFalse();
    });

    it('throws WampClientException and closes the session when reconnect is disabled', function () {
        $transport = new ScriptedTransport([
            [2, 1001, []],
            new TransportException('Connection reset by peer'),
        ]);
        $client = clientOver($transport, reconnect: false);

        $client->connect();

        expect(fn () => $client->listen())
            ->toThrow(WampClientException::class, 'Connection lost');

        expect($client->isConnected())->toBeFalse();
    });
});
