<?php

test('a supported address renders as an svg qr code', function () {
    $this->get(route('qr', 'TEbVBcJFHgz9HHGbgF4fCRJ6Z2WscXhhLp'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
});
