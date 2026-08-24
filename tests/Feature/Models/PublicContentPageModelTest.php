<?php

declare(strict_types=1);

use App\Models\PublicContentPage;

test('public content pages are unique by type', function () {
    PublicContentPage::create([
        'type' => 'terms',
        'content' => '# Terms',
    ]);
    PublicContentPage::create([
        'type' => 'privacy',
        'content' => '# Privacy',
    ]);

    expect(PublicContentPage::count())->toBe(2);
});
