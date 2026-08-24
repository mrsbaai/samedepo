<?php

declare(strict_types=1);

use App\Models\FaqsContent;

test('faqs content can be created', function () {
    $faqs = FaqsContent::create([
        'content' => '# FAQs',
    ]);

    expect($faqs->content)->toBe('# FAQs');
});
