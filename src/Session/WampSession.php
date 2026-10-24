<?php

namespace Hermod\LaravelWamp\Session;

use Hermod\LaravelWamp\Contracts\AuthenticatorContract;
use Hermod\LaravelWamp\Contracts\SerializerContract;
use Hermod\LaravelWamp\Contracts\SessionContract;
use Hermod\LaravelWamp\Contracts\TransportContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;
use Hermod\LaravelWamp\Exceptions\SessionException;
use Hermod\LaravelWamp\Exceptions\TransportException;
use Hermod\LaravelWamp\Exceptions\WampProtocolException;
use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\MessageType;
use Hermod\LaravelWamp\Message\WampMessage;
use Throwable;

/**
 * Manages WAMP client session lifecycles, handshakes, authentication, and transport communications.
 *
 * Implements the SessionContract interface, handling connection opening via transports, 
 * serialization handoffs, HELLO/WELCOME/CHALLENGE authentication sequences, and graceful or abrupt closures.
 */
class WampSession implements SessionContract
{
    /** @var SessionState The current state of the WAMP session. */
    private SessionState $state = SessionState::Closed;

    /** @var int|null The unique session ID assigned by the router upon successful establishment. */
    private ?int $sessionId = null;

    /** @var array<mixed> Metadata and configuration details provided by the router. */
    private array $routerDetails = [];

    /**
     * Create a new WampSession instance.
     *
     * @param  \Hermod\LaravelWamp\Contracts\TransportContract  $transport  The underlying transport layer (e.g., WebSocket).
     * @param  \Hermod\LaravelWamp\Contracts\SerializerContract  $serializer  The protocol serializer (e.g., JSON, CBOR, MessagePack).
     * @param  string  $realm  The WAMP realm to join.
     * @param  \Hermod\LaravelWamp\Contracts\AuthenticatorContract  $authenticator  The session authentication provider.
     */
    public function __construct(
        private readonly TransportContract $transport,
        private readonly SerializerContract $serializer,
        private readonly string $realm,
        private readonly AuthenticatorContract $authenticator,
    ) {
    }

    // -------------------------------------------------------------------------
    // Session Lifecycle Management
    // -------------------------------------------------------------------------

    /**
     * Open the underlying transport connection and initiate the WAMP session handshake (HELLO).
     *
     * If the handshake fails for any reason, the session is left Closed so that it can be retried.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If session is not in a Closed state or handshake fails.
     */
    public function hello(): void
    {
        $this->assertState(SessionState::Closed, 'hello');

        try {
            // Open the underlying transport connection
            $this->transport->connect();
            $this->state = SessionState::Establishing;

            // Send HELLO message with authentication details
            $this->sendMessage(
                MessageFactory::helloWithAuth(
                    realm: $this->realm,
                    authMethod: $this->authenticator->method()->value,
                    authId: $this->authenticator->authId(),
                    authExtra: $this->authenticator->authExtra(),
                ),
            );

            $this->handleAuthSequence();
        } catch (Throwable $e) {
            // A failed handshake must leave the session Closed, so that hello() can be retried
            if ($this->state !== SessionState::Closed) {
                $this->closeSession();
            }

            throw $e;
        }
    }

    /**
     * Gracefully terminate the WAMP session by sending a GOODBYE message and closing the transport.
     */
    public function goodbye(): void
    {
        // If the session is not established, there is nothing to cleanly close
        if ($this->state !== SessionState::Established) {
            $this->closeSession();

            return;
        }

        $this->state = SessionState::Closing;

        try {
            $this->sendMessage(MessageFactory::goodbye());
            $this->receiveMessage();
        } catch (Throwable) {
            // Suppress errors during graceful teardown
        } finally {
            $this->closeSession();
        }
    }

    // -------------------------------------------------------------------------
    // Message Transmission (Used by Caller, Callee, Publisher, Subscriber)
    // -------------------------------------------------------------------------

    /**
     * Send an established WampMessage over the session transport.
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The message to send.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If session is not established.
     * @throws \Hermod\LaravelWamp\Exceptions\TransportException If the connection is lost; the session is closed first.
     */
    public function send(WampMessage $message): void
    {
        $this->assertState(SessionState::Established, 'send');

        try {
            $this->sendMessage($message);
        } catch (TransportException $e) {
            // The connection is gone: mark the session as Closed before propagating
            $this->closeSession();

            throw $e;
        }
    }

    /**
     * Receive and deserialize an incoming WampMessage from the transport.
     *
     * @return WampMessage The received message instance.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If session is not established.
     * @throws \Hermod\LaravelWamp\Exceptions\TransportException If the connection is lost; the session is closed first.
     */
    public function receive(): WampMessage
    {
        $this->assertState(SessionState::Established, 'receive');

        try {
            return $this->receiveMessage();
        } catch (TransportException $e) {
            // The connection is gone: mark the session as Closed before propagating
            $this->closeSession();

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // SessionContract Implementation
    // -------------------------------------------------------------------------

    /**
     * Get the router-assigned session ID.
     *
     * @return int|null The session ID, or null if unestablished.
     */
    public function getSessionId(): ?int
    {
        return $this->sessionId;
    }

    /**
     * Get the target WAMP realm name.
     *
     * @return string The realm URI.
     */
    public function getRealm(): string
    {
        return $this->realm;
    }

    /**
     * Determine whether the session is successfully established.
     *
     * @return bool True if established, false otherwise.
     */
    public function isEstablished(): bool
    {
        return $this->state === SessionState::Established;
    }

    /**
     * Get the current state of the session.
     *
     * @return SessionState The session state enum instance.
     */
    public function getState(): SessionState
    {
        return $this->state;
    }

    /**
     * Get router details and capabilities returned during welcome handshake.
     *
     * @return array<mixed> Array of router detail parameters.
     */
    public function getRouterDetails(): array
    {
        return $this->routerDetails;
    }

    /**
     * Get the session authenticator instance.
     *
     * @return AuthenticatorContract The authenticator service.
     */
    public function getAuthenticator(): AuthenticatorContract
    {
        return $this->authenticator;
    }

    // -------------------------------------------------------------------------
    // Authentication Handshake Flow Helpers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming messages during the authentication sequence (WELCOME, CHALLENGE, or ABORT).
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If an unexpected message type is received.
     */
    private function handleAuthSequence(): void
    {
        $message = $this->receiveMessage();

        match ($message->type()) {
            MessageType::WELCOME => $this->handleWelcome($message),
            MessageType::CHALLENGE => $this->handleChallenge($message),
            MessageType::ABORT => $this->handleAbort($message),
            default => throw new SessionException(
                "Unexpected response during WAMP handshake: {$message->type()->name}",
            ),
        };
    }

    /**
     * Handle incoming CHALLENGE messages by generating signatures and sending AUTHENTICATE.
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming CHALLENGE message.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\AuthenticationException If challenges are unsupported or signature generation fails.
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If an unexpected response occurs after authentication.
     */
    private function handleChallenge(WampMessage $message): void
    {
        // [4, authmethod, extra]
        $authMethod = (string) ($message->get(1) ?? '');
        $extra = (array) ($message->get(2) ?? []);

        if (!$this->authenticator->requiresChallenge()) {
            throw new AuthenticationException(
                "The router sent a CHALLENGE, but the authenticator " .
                "'{$this->authenticator->method()->value}' does not support it.",
            );
        }

        // Calculate challenge signature response
        $signature = $this->authenticator->handleChallenge(
            challenge: $extra['challenge'] ?? '',
            extra: $extra,
        );

        if ($signature === null) {
            throw new AuthenticationException(
                'Failed to generate signature for WAMP challenge.',
            );
        }

        // Send AUTHENTICATE message
        $this->sendMessage(MessageFactory::authenticate($signature));

        // Await WELCOME or ABORT response
        $response = $this->receiveMessage();

        match ($response->type()) {
            MessageType::WELCOME => $this->handleWelcome($response),
            MessageType::ABORT => $this->handleAbort($response),
            default => throw new SessionException(
                "Unexpected response following AUTHENTICATE: {$response->type()->name}",
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Incoming Message Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming WELCOME messages, establishing the session state.
     * Expected format: [2, sessionId, details]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming WELCOME message.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If session ID is invalid.
     */
    private function handleWelcome(WampMessage $message): void
    {
        $sessionId = $message->get(1);

        if (!is_int($sessionId)) {
            throw new SessionException(
                'Invalid session ID received in WELCOME message.',
            );
        }

        $this->sessionId = $sessionId;
        $this->routerDetails = $message->get(2) ?? [];
        $this->state = SessionState::Established;
    }

    /**
     * Handle incoming ABORT messages, closing session and throwing corresponding exceptions.
     * Expected format: [3, details, reason]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming ABORT message.
     * @return never
     *
     * @throws \Hermod\LaravelWamp\Exceptions\AuthenticationException If aborted due to authorization failure.
     * @throws \Hermod\LaravelWamp\Exceptions\WampProtocolException For other protocol rejections.
     */
    private function handleAbort(WampMessage $message): void
    {
        $reason = $message->get(2) ?? 'wamp.error.unknown';
        $details = $message->get(1) ?? [];

        $this->closeSession();

        $isAuthError = str_contains($reason, 'not_authorized')
            || str_contains($reason, 'authentication');

        if ($isAuthError) {
            throw new AuthenticationException(
                "Authentication rejected by router: {$reason}",
                wampError: $reason,
                details: $details,
            );
        }

        throw new WampProtocolException(
            "WAMP connection rejected by router: {$reason}",
            wampError: $reason,
            details: $details,
        );
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    /**
     * Serialize and transmit a WampMessage across the transport layer.
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The message to transmit.
     */
    private function sendMessage(WampMessage $message): void
    {
        $raw = $this->serializer->serialize($message->toArray());
        $this->transport->send($raw);
    }

    /**
     * Receive raw transport data and deserialize it into a WampMessage.
     *
     * @return WampMessage The deserialized message.
     */
    private function receiveMessage(): WampMessage
    {
        $raw = $this->transport->receive();
        $data = $this->serializer->deserialize($raw);

        return WampMessage::fromArray($data);
    }

    /**
     * Forcefully reset session state variables and close transport connections.
     */
    private function closeSession(): void
    {
        $this->state = SessionState::Closed;
        $this->sessionId = null;

        try {
            $this->transport->close();
        } catch (Throwable) {
            // Suppress transport closure errors
        }
    }

    /**
     * Assert that the session is currently in the expected state.
     *
     * @param  SessionState  $expected  The expected state.
     * @param  string  $operation  The name of the operation being attempted.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\SessionException If state validation fails.
     */
    private function assertState(SessionState $expected, string $operation): void
    {
        if ($this->state !== $expected) {
            throw new SessionException(
                "Cannot execute '{$operation}': " .
                "current state is '{$this->state->name}', " .
                "expected state is '{$expected->name}'.",
            );
        }
    }
}