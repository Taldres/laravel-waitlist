<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Events\WordingRegistered;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistWording;
use Taldres\Waitlist\Support\RequestContext;

beforeEach(function () {
    $this->skipWithoutSecondConnection();

    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Gap locks make the other insert wait for this transaction; the deadlock retry is covered in CallerWordingTest.');
    }

    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->wordingFromCallers();
        $project->list('beta', purpose: 'launch');
    });

    // Another signup registers the version on client b after this one found
    // nothing, just before it inserts.
    $this->raceWith = function (string $text): void {
        $raced = false;

        WaitlistWording::creating(function () use (&$raced, $text): void {
            if ($raced) {
                return;
            }

            $raced = true;

            $this->as('b', fn () => WaitlistWording::query()->create([
                'project' => 'acme',
                'purpose' => 'launch',
                'version' => '2026-10',
                'locale' => '',
                'text' => $text,
                'registered_by' => 'the other server',
                'registered_at' => Carbon::now(),
            ]));
        });
    };

    $this->signUp = fn (string $text) => Waitlist::project('acme')->for('beta')->add(
        'user@example.com',
        ['launch' => ['version' => '2026-10', 'text' => $text]],
        context: new RequestContext(caller: 'this server'),
    );
});

it('takes the version a concurrent signup registered first with the same text', function () {
    Event::fake([WordingRegistered::class]);
    ($this->raceWith)('Email me at launch.');

    ($this->signUp)('Email me at launch.');

    expect(WaitlistWording::query()->sole()->registered_by)->toBe('the other server')
        ->and(WaitlistConsent::query()->sole()->text)->toBe('Email me at launch.');

    Event::assertNotDispatched(WordingRegistered::class);
});

it('refuses the signup when a concurrent one registered other text first', function () {
    ($this->raceWith)('Email me about everything.');

    expect(fn () => ($this->signUp)('Email me at launch.'))->toThrow(WordingConflictException::class);

    expect(WaitlistWording::query()->sole()->text)->toBe('Email me about everything.')
        ->and(WaitlistConsent::query()->count())->toBe(0);
});
