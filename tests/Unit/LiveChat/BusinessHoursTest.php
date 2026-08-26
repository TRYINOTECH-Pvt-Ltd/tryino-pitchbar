<?php

use App\Models\Workspace;
use App\Services\LiveChat\BusinessHours;
use Carbon\Carbon;

beforeEach(function () {
    $this->bh = new BusinessHours;
});

test('null business_hours config means always open', function () {
    $w = new Workspace;
    $w->business_hours = null;

    expect($this->bh->isOpen($w, Carbon::parse('2026-05-09 03:00:00 UTC')))->toBeTrue();
});

test('disabled business_hours means always open', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => false,
        'timezone' => 'UTC',
        'schedule' => ['monday' => [['start' => '09:00', 'end' => '17:00']]],
    ];

    expect($this->bh->isOpen($w, Carbon::parse('2026-05-10 03:00:00 UTC')))->toBeTrue();
});

test('open during a configured weekday window', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    // 2026-05-04 is a Monday, 10am UTC = inside the window.
    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 10:00:00 UTC')))->toBeTrue();
});

test('closed before the day-start', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 08:30:00 UTC')))->toBeFalse();
});

test('closed on a day with no slots', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
            'saturday' => [],
        ],
    ];

    // 2026-05-09 is a Saturday.
    expect($this->bh->isOpen($w, Carbon::parse('2026-05-09 12:00:00 UTC')))->toBeFalse();
});

test('respects workspace timezone — same UTC moment is open in NY but closed in Tokyo', function () {
    $nyWorkspace = new Workspace;
    $nyWorkspace->business_hours = [
        'enabled' => true,
        'timezone' => 'America/New_York',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
            'tuesday' => [['start' => '09:00', 'end' => '17:00']],
            'wednesday' => [['start' => '09:00', 'end' => '17:00']],
            'thursday' => [['start' => '09:00', 'end' => '17:00']],
            'friday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    $tokyoWorkspace = new Workspace;
    $tokyoWorkspace->business_hours = [
        'enabled' => true,
        'timezone' => 'Asia/Tokyo',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
            'tuesday' => [['start' => '09:00', 'end' => '17:00']],
            'wednesday' => [['start' => '09:00', 'end' => '17:00']],
            'thursday' => [['start' => '09:00', 'end' => '17:00']],
            'friday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    // 2026-05-04 14:00 UTC = Monday 10am NYC = Monday 11pm Tokyo.
    $when = Carbon::parse('2026-05-04 14:00:00 UTC');

    expect($this->bh->isOpen($nyWorkspace, $when))->toBeTrue();
    expect($this->bh->isOpen($tokyoWorkspace, $when))->toBeFalse();
});

test('multiple windows per day (lunch break)', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [
                ['start' => '09:00', 'end' => '12:00'],
                ['start' => '13:00', 'end' => '17:00'],
            ],
        ],
    ];

    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 11:00:00 UTC')))->toBeTrue();
    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 12:30:00 UTC')))->toBeFalse();
    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 14:00:00 UTC')))->toBeTrue();
});

test('nextOpenAt returns null when always-open or already open', function () {
    $w = new Workspace;
    $w->business_hours = null;
    expect($this->bh->nextOpenAt($w))->toBeNull();

    $w2 = new Workspace;
    $w2->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    expect($this->bh->nextOpenAt($w2, Carbon::parse('2026-05-04 10:00:00 UTC')))->toBeNull();
});

test('nextOpenAt finds the next configured window when currently closed', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => '09:00', 'end' => '17:00']],
        ],
    ];

    // 2026-05-04 is Monday. At 19:00 we're past today's window;
    // next open window is next Monday 09:00 UTC.
    $next = $this->bh->nextOpenAt($w, Carbon::parse('2026-05-04 19:00:00 UTC'));
    expect($next)->toBeString()->and(str_contains($next, '2026-05-11T09:00:00'))->toBeTrue();
});

test('malformed time strings are silently skipped', function () {
    $w = new Workspace;
    $w->business_hours = [
        'enabled' => true,
        'timezone' => 'UTC',
        'schedule' => [
            'monday' => [['start' => 'not-a-time', 'end' => '17:00']],
        ],
    ];

    // No usable slot → closed today.
    expect($this->bh->isOpen($w, Carbon::parse('2026-05-04 10:00:00 UTC')))->toBeFalse();
});
