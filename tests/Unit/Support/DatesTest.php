<?php

use App\Support\Dates;
use Illuminate\Support\Carbon;

test('humanFlat returns an empty string for null', function (): void {
    expect(Dates::humanFlat(null))->toBe('');
});

test('humanFlat renders the relative time with the absolute UTC timestamp', function (): void {
    Carbon::setTestNow('2026-09-20 15:00:00');

    $at = Carbon::parse('2026-09-17 14:02:00', 'UTC');

    expect(Dates::humanFlat($at))->toBe('3 days ago (2026-09-17 14:02)');

    Carbon::setTestNow();
});

test('humanFlat formats sub-day timestamps', function (): void {
    Carbon::setTestNow('2026-09-20 15:00:00');

    $at = Carbon::parse('2026-09-20 13:00:00', 'UTC');

    expect(Dates::humanFlat($at))->toBe('2 hours ago (2026-09-20 13:00)');

    Carbon::setTestNow();
});
