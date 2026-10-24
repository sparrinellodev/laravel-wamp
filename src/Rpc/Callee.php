<?php

namespace Hermod\LaravelWamp\Rpc;

use Hermod\LaravelWamp\Contracts\CalleeContract;
use Hermod\LaravelWamp\Exceptions\CalleeException;
use Hermod\LaravelWamp\Message\MessageFactory;
use Hermod\LaravelWamp\Message\WampMessage;
use Hermod\LaravelWamp\Session\WampSession;

/**
 * Manages WAMP procedure registrations, unregistrations, and incoming RPC invocations.
 *
 * Implements the CalleeContract interface, coordinating registration lifecycles 
 * through pending tracking registries, executing local handler callbacks, and 
 * transmitting successful YIELDS or runtime ERROR responses back through the session.
 */
class Callee implements CalleeContract
{
    /** @var array<string, array{requestId: int, handler: callable}> procedure → pending registration info */
    private array $pendingRegistrations = [];

    /** @var array<int, Registration> registrationId → Registration */
    private array $registrations = [];

    /**
     * Create a new Callee instance.
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
    // CalleeContract Implementation
    // -------------------------------------------------------------------------

    /**
     * Register a local procedure with the WAMP router to make it available for remote invocation.
     *
     * @param  string  $procedure  The URI of the procedure to register (e.g., 'com.myapp.sum').
     * @param  callable  $handler  The callback invoked when the procedure is remotely invoked.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\CalleeException If the procedure is already registered or pending.
     */
    public function register(string $procedure, callable $handler): void
    {
        if ($this->isProcedureRegistered($procedure)) {
            throw new CalleeException(
                "Procedure '{$procedure}' is already registered.",
            );
        }

        $requestId = $this->idGenerator->generate();

        // Save pending metadata — will be associated with the registrationId in onRegistered()
        $this->pendingRegistrations[$procedure] = [
            'requestId' => $requestId,
            'handler' => $handler,
        ];

        $this->session->send(
            MessageFactory::register($requestId, $procedure),
        );
    }

    /**
     * Unregister a procedure by its URI, removing it from the WAMP router.
     *
     * @param  string  $procedure  The URI of the procedure to unregister.
     *
     * @throws \Hermod\LaravelWamp\Exceptions\CalleeException If the procedure is not currently registered.
     */
    public function unregister(string $procedure): void
    {
        $registration = $this->findRegistrationByProcedure($procedure);

        if ($registration === null) {
            throw new CalleeException(
                "Procedure '{$procedure}' is not registered.",
            );
        }

        $requestId = $this->idGenerator->generate();

        $this->session->send(
            MessageFactory::unregister($requestId, $registration->registrationId),
        );

        unset($this->registrations[$registration->registrationId]);
    }

    /**
     * Get all active procedure registrations mapped as [procedure => handler].
     *
     * @return array<string, callable> Array of active procedure handlers.
     */
    public function getRegistrations(): array
    {
        $result = [];

        foreach ($this->registrations as $registration) {
            $result[$registration->procedure] = $registration->handler;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Session Recovery
    // -------------------------------------------------------------------------

    /**
     * Forget every registration, active or pending, and return their handlers.
     *
     * Used after the connection is lost: the router has already dropped the old
     * session's registrations, so they must be registered again on a new session.
     *
     * @return array<string, callable> Procedure URI → handler.
     */
    public function flush(): array
    {
        $handlers = $this->getRegistrations();

        foreach ($this->pendingRegistrations as $procedure => $pending) {
            $handlers[$procedure] = $pending['handler'];
        }

        $this->registrations = [];
        $this->pendingRegistrations = [];

        return $handlers;
    }

    // -------------------------------------------------------------------------
    // Incoming Message Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle incoming REGISTERED messages dispatched from the router.
     * Expected format: [65, requestId, registrationId]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming REGISTERED message.
     */
    public function onRegistered(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $registrationId = (int) $message->get(2);

        // Find the pending registration via request ID
        $pending = $this->findPendingByRequestId($requestId);

        if ($pending === null) {
            return; // Unknown request ID — ignore
        }

        ['procedure' => $procedure, 'handler' => $handler] = $pending;

        // Save the definitive registration mapping
        $this->registrations[$registrationId] = new Registration(
            registrationId: $registrationId,
            procedure: $procedure,
            handler: $handler,
        );

        // Remove from pending cache
        unset($this->pendingRegistrations[$procedure]);
    }

    /**
     * Handle incoming INVOCATION messages dispatched from the router.
     * Expected format: [68, requestId, registrationId, details, args?, kwargs?]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming INVOCATION message.
     */
    public function onInvocation(WampMessage $message): void
    {
        $requestId = (int) $message->get(1);
        $registrationId = (int) $message->get(2);
        $args = $message->get(4) ?? [];
        $kwargs = $message->get(5) ?? [];

        if (!isset($this->registrations[$registrationId])) {
            // Unknown registration — send error back to the router
            $this->session->send(
                MessageFactory::yieldError(
                    $requestId,
                    'wamp.error.no_such_registration',
                ),
            );

            return;
        }

        $registration = $this->registrations[$registrationId];

        try {
            // Execute the registered handler callback
            $result = ($registration->handler)(
                is_array($args) ? $args : [],
                is_array($kwargs) ? $kwargs : [],
            );

            // Normalize the result into an array format for YIELD
            $resultArgs = is_array($result) ? $result : [$result];

            // Send successful YIELD response to the router
            $this->session->send(
                MessageFactory::yield($requestId, $resultArgs),
            );
        } catch (\Throwable $e) {
            // If the handler throws an exception, send an ERROR response to the router
            $this->session->send(
                MessageFactory::yieldError(
                    $requestId,
                    'wamp.error.runtime_error',
                    [$e->getMessage()],
                ),
            );
        }
    }

    /**
     * Handle incoming UNREGISTERED messages dispatched from the router.
     * Expected format: [67, requestId]
     *
     * @param  \Hermod\LaravelWamp\Message\WampMessage  $message  The incoming UNREGISTERED message.
     */
    public function onUnregistered(WampMessage $message): void
    {
        // Confirmation is handled proactively during unregister() calls; 
        // reserved for logging or event hooks if required.
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a procedure is already registered or currently pending registration.
     *
     * @param  string  $procedure  The procedure URI.
     * @return bool True if registered or pending, false otherwise.
     */
    private function isProcedureRegistered(string $procedure): bool
    {
        return $this->findRegistrationByProcedure($procedure) !== null
            || isset($this->pendingRegistrations[$procedure]);
    }

    /**
     * Find an active registration by its procedure URI.
     *
     * @param  string  $procedure  The procedure URI.
     * @return Registration|null The matching registration instance, or null if not found.
     */
    private function findRegistrationByProcedure(string $procedure): ?Registration
    {
        foreach ($this->registrations as $registration) {
            if ($registration->procedure === $procedure) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Find pending registration details matching a given request ID.
     *
     * @param  int  $requestId  The request ID.
     * @return array{procedure: string, requestId: int, handler: callable}|null Array containing pending data and procedure name.
     */
    private function findPendingByRequestId(int $requestId): ?array
    {
        foreach ($this->pendingRegistrations as $procedure => $pending) {
            if ($pending['requestId'] === $requestId) {
                return [...$pending, 'procedure' => $procedure];
            }
        }

        return null;
    }
}