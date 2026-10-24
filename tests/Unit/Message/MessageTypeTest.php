<?php

use Hermod\LaravelWamp\Message\MessageType;

describe('MessageType', function () {

    it('has the correct values for session messages', function () {
        expect(MessageType::HELLO->value)->toBe(1)
            ->and(MessageType::WELCOME->value)->toBe(2)
            ->and(MessageType::ABORT->value)->toBe(3)
            ->and(MessageType::GOODBYE->value)->toBe(6);
    });

    it('has the correct values for RPC messages', function () {
        expect(MessageType::CALL->value)->toBe(48)
            ->and(MessageType::RESULT->value)->toBe(50)
            ->and(MessageType::REGISTER->value)->toBe(64)
            ->and(MessageType::REGISTERED->value)->toBe(65)
            ->and(MessageType::INVOCATION->value)->toBe(68)
            ->and(MessageType::YIELD->value)->toBe(70);
    });

    it('resolves a type from a valid integer', function () {
        expect(MessageType::tryFrom(48))->toBe(MessageType::CALL)
            ->and(MessageType::tryFrom(50))->toBe(MessageType::RESULT)
            ->and(MessageType::tryFrom(64))->toBe(MessageType::REGISTER);
    });

    it('returns null for an unknown type', function () {
        expect(MessageType::tryFrom(999))->toBeNull();
    });
});
