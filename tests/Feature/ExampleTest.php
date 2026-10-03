<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the application returns a successful response', function () {
    $this->withoutVite();

    $response = $this->get('/');

    $response->assertStatus(200);
});
