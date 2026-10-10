<?php

declare(strict_types=1);

use Illuminate\Support\LazyCollection;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\Recipient;

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    require __DIR__.'/../../routes/waitlist.php';

    $this->both = [...waitlistConsent(), 'newsletter' => '2026-10'];
    $this->confirmed = function (string $list, string $email, ?array $purposes = null, string $project = 'default'): array {
        $tokens = subscribeAndCapture($list, $email, $purposes ?? $this->both, $project);
        Waitlist::confirm($tokens['confirm']);

        return $tokens;
    };
});

it('mails each address once, and only where the purpose is in force', function () {
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('launch', ['v1' => 'Acme launch.']);
        $project->purpose('newsletter', ['v1' => 'Acme news.']);
        $project->list('beta', purpose: 'launch')->optional('newsletter');
    });

    ($this->confirmed)('beta', 'jane@example.com');
    ($this->confirmed)('launch', 'jane@example.com');
    ($this->confirmed)('beta', 'bob@example.com', waitlistConsent());
    subscribeAndCapture('beta', 'carl@example.com', $this->both);
    Waitlist::unsubscribe(($this->confirmed)('launch', 'dana@example.com')['unsubscribe']);
    ($this->confirmed)('beta', 'eve@example.com', ['launch' => 'v1', 'newsletter' => 'v1'], 'acme');

    $recipients = Waitlist::recipients('newsletter');

    expect($recipients)->toBeInstanceOf(LazyCollection::class)
        ->and($recipients->map(fn (Recipient $recipient) => $recipient->email)->all())->toBe(['jane@example.com'])
        ->and(Waitlist::project('acme')->recipients('newsletter')->map->email->all())->toBe(['eve@example.com'])
        ->and(Waitlist::for('beta')->recipients()->map->email->all())->toBe(['jane@example.com', 'bob@example.com']);
});

it('links a mail for a purpose to withdrawing that purpose, on every list', function () {
    ($this->confirmed)('beta', 'jane@example.com');
    ($this->confirmed)('launch', 'jane@example.com');

    $recipient = Waitlist::recipients('newsletter')->sole();

    expect($recipient->unsubscribeUrl())->toContain('?purpose=newsletter')
        ->and($recipient->listUnsubscribeHeaders()['List-Unsubscribe'])->toContain('purpose=newsletter');

    $this->post(parse_url($recipient->unsubscribeUrl(), PHP_URL_PATH).'?purpose=newsletter', ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(Waitlist::recipients('newsletter')->all())->toBe([])
        ->and(Waitlist::for('launch')->recipients()->map->email->all())->toBe(['jane@example.com']);
});

it('links a mail about one list to leaving that list', function () {
    ($this->confirmed)('beta', 'jane@example.com');
    ($this->confirmed)('launch', 'jane@example.com');

    $recipient = Waitlist::for('beta')->recipients()->sole();

    expect($recipient->purpose)->toBe('waitlist')
        ->and($recipient->unsubscribeUrl())->not->toContain('purpose')
        ->and(Waitlist::for('beta')->recipients('newsletter')->sole()->unsubscribeUrl())->toContain('?purpose=newsletter');

    Waitlist::unsubscribe(Waitlist::unsubscribeToken($recipient->entry)->token);

    expect(Waitlist::for('beta')->recipients()->all())->toBe([])
        ->and(Waitlist::for('launch')->recipients()->map->email->all())->toBe(['jane@example.com']);
});

it('carries the locale the consent was given in', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('newsletter', ['2026-10' => ['en' => 'Newsletter.', 'de' => 'Newsletter auf Deutsch.']]));
    ($this->confirmed)('beta', 'jane@example.com', [...waitlistConsent(), 'newsletter' => ['version' => '2026-10', 'locale' => 'de']]);

    expect(Waitlist::recipients('newsletter')->sole()->locale)->toBe('de')
        ->and(Waitlist::for('beta')->recipients()->sole()->locale)->toBeNull();
});

it('streams beyond one chunk and still counts each address once', function () {
    config()->set(ConfigKey::DoubleOptIn->value, false);

    foreach (range(1, 520) as $n) {
        Waitlist::for('beta')->add("user{$n}@example.com", $this->both);
        Waitlist::for('launch')->add("user{$n}@example.com", $this->both);
    }

    expect(Waitlist::recipients('newsletter')->count())->toBe(520);
});
