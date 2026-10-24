<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Auth\AuthMethod;

describe('AnonymousAuthenticator', function () {

    beforeEach(function () {
        $this->auth = new AnonymousAuthenticator;
    });

    it('returns the correct method', function () {
        expect($this->auth->method())->toBe(AuthMethod::Anonymous);
    });

    it('has no authId', function () {
        expect($this->auth->authId())->toBeNull();
    });

    it('has no authExtra', function () {
        expect($this->auth->authExtra())->toBe([]);
    });

    it('does not require a challenge', function () {
        expect($this->auth->requiresChallenge())->toBeFalse();
    });

    it('returns null from handleChallenge', function () {
        expect($this->auth->handleChallenge('qualsiasi challenge'))->toBeNull();
    });
});
