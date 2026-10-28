<?php

use Tests\TestCase;

test('returns a successful response', function () {
    /** @var TestCase $this */
    $response = $this->get('/');

    $response->assertOk();
});
