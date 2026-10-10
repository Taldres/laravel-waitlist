<?php

declare(strict_types=1);

use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;

beforeEach(function () {
    defineLocaleTestProject();
});

/**
 * @param  (Closure(ProjectDefinition): mixed)|null  $extend
 */
function defineLocaleTestProject(?Closure $extend = null): void
{
    defineDefaultProject(function (ProjectDefinition $project) use ($extend): void {
        $project->purpose('waitlist', [
            '2026-09' => 'Earlier wording.',
            '2026-10' => ['en' => 'Email me when early access opens.', 'de' => 'Schreibt mir, wenn der Zugang startet.'],
        ]);
        $project->purpose('newsletter', [
            '2026-10' => ['en' => 'Also send me the newsletter.', 'de' => 'Schickt mir auch den Newsletter.'],
        ]);

        if ($extend !== null) {
            $extend($project);
        }
    });
}

it('offers the wording in the locale asked for, and says which one it is', function () {
    [$waitlist, $newsletter] = Waitlist::purposes('beta', locale: 'de');

    expect($waitlist)
        ->version->toBe('2026-10')
        ->locale->toBe('de')
        ->text->toBe('Schreibt mir, wenn der Zugang startet.')
        ->and($newsletter->locale)->toBe('de');
});

it('shows the app locale by default, then the fallback locale, then the first text', function () {
    app()->setLocale('de');

    expect(Waitlist::for('beta')->purposes()[0]->locale)->toBe('de');

    app()->setLocale('fr');
    config()->set('app.fallback_locale', 'en');

    expect(Waitlist::purposes('beta', locale: 'fr')[0]->locale)->toBe('en');

    config()->set('app.fallback_locale', 'it');

    expect(Waitlist::project('default')->purposes('beta', 'fr')[0]->locale)->toBe('en');
});

it('stores the locale the wording was shown in with the consent', function () {
    $result = Waitlist::for('beta')->add('user@example.com', [
        'waitlist' => ['version' => '2026-10', 'locale' => 'de'],
        'newsletter' => ['version' => '2026-10', 'locale' => 'en'],
    ]);

    expect($result->subscription->consents->map->only('purpose', 'locale', 'text')->all())->toBe([
        ['purpose' => 'waitlist', 'locale' => 'de', 'text' => 'Schreibt mir, wenn der Zugang startet.'],
        ['purpose' => 'newsletter', 'locale' => 'en', 'text' => 'Also send me the newsletter.'],
    ]);
});

it('needs the locale a version with several texts was shown in', function (mixed $choice) {
    Waitlist::for('beta')->add('user@example.com', ['waitlist' => $choice]);
})->with([
    'no locale' => [['version' => '2026-10']],
    'just the version' => ['2026-10'],
    'a locale it does not have' => [['version' => '2026-10', 'locale' => 'fr']],
])->throws(UnknownPurposeException::class);

it('takes a version with a single text for any locale', function () {
    $consent = Waitlist::for('beta')->add('user@example.com', ['waitlist' => ['version' => '2026-09', 'locale' => 'de']])
        ->subscription->consents->sole();

    expect($consent->locale)->toBeNull()
        ->and($consent->text)->toBe('Earlier wording.');
});

it('adds an optional purpose in the locale the preference page showed', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com', ['waitlist' => ['version' => '2026-10', 'locale' => 'de']]);
    Waitlist::confirm($tokens['confirm']);

    Waitlist::grantConsent(manageTokenFor($tokens['entry']), 'newsletter', '2026-10', 'de');

    expect(WaitlistConsent::query()->where('purpose', 'newsletter')->sole()->locale)->toBe('de')
        ->and(Waitlist::personalData('user@example.com')->sole()->toArray()['subscriptions'][0]['consents'][1])
        ->toMatchArray(['purpose' => 'newsletter', 'locale' => 'de', 'text' => 'Schickt mir auch den Newsletter.']);
});

describe('over HTTP', function () {
    beforeEach(function () {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';
    });

    it('serves the wording in a locale and takes back what it served', function () {
        $served = $this->getJson('/waitlist/purposes?list=beta&locale=de')
            ->assertOk()
            ->assertJsonPath('data.0.locale', 'de')
            ->assertJsonPath('data.0.text', 'Schreibt mir, wenn der Zugang startet.')
            ->json('data.0');

        $this->postJson('/waitlist', [
            'email' => 'user@example.com',
            'list' => 'beta',
            'purposes' => ['waitlist' => ['version' => $served['version'], 'locale' => $served['locale']]],
        ])->assertStatus(202);

        expect(WaitlistConsent::query()->sole()->locale)->toBe('de');
    });

    it('sets the locale on the preference page too', function () {
        $tokens = subscribeAndCapture('beta', 'user@example.com', ['waitlist' => ['version' => '2026-10', 'locale' => 'de']]);
        Waitlist::confirm($tokens['confirm']);

        $this->putJson('/waitlist/manage/'.manageTokenFor($tokens['entry']).'/purposes', ['purposes' => [
            'waitlist' => ['version' => '2026-10', 'locale' => 'de'],
            'newsletter' => ['version' => '2026-10', 'locale' => 'de'],
        ]])->assertOk()->assertJsonPath('data.purposes', ['waitlist', 'newsletter']);

        expect(WaitlistConsent::query()->where('purpose', 'newsletter')->sole()->locale)->toBe('de');
    });

    it('refuses choices that carry anything but version, locale and hash', function (mixed $choice) {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => ['waitlist' => $choice]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purposes.waitlist');
    })->with([
        'a key of its own' => [['version' => '2026-10', 'locale' => 'de', 'admin' => true]],
        'no version' => [['locale' => 'de']],
        'a nested version' => [['version' => ['2026-10']]],
    ]);
});

it('builds fixtures from wording per locale, on the list\'s own primary purpose', function () {
    defineLocaleTestProject(function (ProjectDefinition $project): void {
        $project->purpose('newsletter', ['2026-10' => ['en' => 'Send me the newsletter.', 'de' => 'Schickt mir den Newsletter.']]);
        $project->list('news', purpose: 'newsletter');
        $project->list('*', purpose: 'waitlist');
    });
    app()->setLocale('de');

    $consent = WaitlistEntry::factory()->onList('news')->confirmed()->create()->latestSubscription->consents->sole();

    expect([$consent->purpose, $consent->locale, $consent->text])->toBe(['newsletter', 'de', 'Schickt mir den Newsletter.']);
});
