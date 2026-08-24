<?php

it('renders the umani tracking script when a website id is configured', function () {
    config(['services.umani.website_id' => 'test-website-id']);

    $html = view('components.umani-analytics')->render();

    expect($html)->toContain('lilstat.com/lilstat.js')
        ->and($html)->toContain('test-website-id');
});

it('does not render the umani tracking script when no website id is configured', function () {
    config(['services.umani.website_id' => null]);

    $html = view('components.umani-analytics')->render();

    expect($html)->not->toContain('lilstat.com/lilstat.js');
});
