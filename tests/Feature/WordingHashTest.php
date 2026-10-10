<?php

declare(strict_types=1);

use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\WordingMismatchException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;

beforeEach(function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('newsletter', [
        '2026-10' => ['en' => 'Also send me the newsletter.', 'de' => 'Schickt mir auch den Newsletter.'],
    ]));
});

it('serves a hash of each wording', function () {
    [$waitlist, $newsletter] = Waitlist::purposes('beta', locale: 'de');

    expect($waitlist->hash())->toBe(hash('sha256', 'Email me when early access opens.'))
        ->and($newsletter->toArray()['hash'])->toBe(hash('sha256', 'Schickt mir auch den Newsletter.'));
});

it('takes a choice whose hash matches the wording it names', function () {
    Waitlist::for('beta')->add('user@example.com', [
        'waitlist' => ['version' => '2026-10', 'hash' => hash('sha256', 'Email me when early access opens.')],
        'newsletter' => ['version' => '2026-10', 'locale' => 'de', 'hash' => hash('sha256', 'Schickt mir auch den Newsletter.')],
    ]);

    expect(WaitlistConsent::query()->count())->toBe(2);
});

it('refuses a choice whose form showed other wording than registered', function () {
    Waitlist::for('beta')->add('user@example.com', [
        'waitlist' => ['version' => '2026-10', 'hash' => hash('sha256', 'Email me when early access opens!')],
    ]);
})->throws(WordingMismatchException::class, 'The wording shown for [waitlist] does not match version [2026-10].');

it('can require a hash for every choice', function () {
    config()->set(ConfigKey::WordingRequireHash->value, true);

    expect(fn () => Waitlist::for('beta')->add('user@example.com', waitlistConsent()))
        ->toThrow(WordingMismatchException::class, 'needs the hash of the wording shown');

    Waitlist::for('beta')->add('user@example.com', ['waitlist' => ['version' => '2026-10', 'hash' => hash('sha256', 'Email me when early access opens.')]]);

    expect(WaitlistConsent::query()->count())->toBe(1);
});

it('answers a mismatch over HTTP with a 422 on the purpose', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    require __DIR__.'/../../routes/waitlist.php';

    $this->postJson('/waitlist', [
        'email' => 'user@example.com',
        'list' => 'beta',
        'purposes' => ['waitlist' => ['version' => '2026-10', 'hash' => str_repeat('0', 64)]],
    ])->assertStatus(422)->assertJsonPath('errors.purposes.0', 'The wording shown for [waitlist] does not match version [2026-10].');

    expect(WaitlistConsent::query()->count())->toBe(0);
});
