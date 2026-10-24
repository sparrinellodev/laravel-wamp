<?php

use Hermod\LaravelWamp\Rpc\RequestIdGenerator;

describe('RequestIdGenerator', function () {

    beforeEach(function () {
        $this->generator = new RequestIdGenerator;
    });

    it('generates a positive integer', function () {
        expect($this->generator->generate())
            ->toBeInt()
            ->toBeGreaterThan(0);
    });

    it('stays within the WAMP range [1, 2^53]', function () {
        $id = $this->generator->generate();

        expect($id)
            ->toBeGreaterThanOrEqual(1)
            ->toBeLessThanOrEqual(9007199254740992);
    });

    it('generates different IDs on successive calls', function () {
        $ids = array_map(
            fn () => $this->generator->generate(),
            range(1, 100),
        );

        // With 100 random IDs in a 2^53 range the probability
        // of a collision is astronomically low
        expect(count(array_unique($ids)))->toBe(100);
    });
});
