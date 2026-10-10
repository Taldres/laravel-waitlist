<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Support\StoredWordingCatalog;

function privacyRecord(): string
{
    Artisan::call('waitlist:privacy');

    return Artisan::output();
}

it('describes data, purposes and retention from the configuration', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string']]));
    config()->set(ConfigKey::StoreIp->value, true);

    $record = privacyRecord();

    expect($record)
        ->toContain('| Email address | waitlist_entries.email | Encrypted with APP_KEY; looked up by a keyed hash |')
        ->toContain('| Metadata (default, any other list): source | waitlist_entries.metadata | Encrypted with APP_KEY |')
        ->toContain('| IP address | waitlist_activity | Encrypted with APP_KEY |')
        ->not->toContain('User agent |')
        ->toContain('| default | any other list | waitlist | yes | 2026-10 | Email me when early access opens. | yes |')
        ->toContain('| default | any other list | newsletter | no | 2026-10 | Also send me the newsletter. | yes |')
        ->toContain('- Unconfirmed signups erased: after 30 days')
        ->toContain('- Addresses that left erased: after 1095 days')
        ->toContain('on the schedule `15 3 * * *`')
        ->toContain('- Confirmation mails limited to one per 5 minutes and 5 per cycle')
        ->toContain('- At most 5 confirmation requests per address and day for unconfirmed lists of a project; further ones are held back')
        ->toContain('- No public endpoints (package routes disabled)');

    config()->set(ConfigKey::RoutesEnabled->value, true);

    expect(privacyRecord())->toContain('- Rate limits: signups 10 per minute and IP, token links 10 per minute and token; GET never changes state');

    config()->set(ConfigKey::SignupLimiter->value, 'signup-strict');
    config()->set(ConfigKey::LinksLimiter->value, null);

    expect(privacyRecord())->toContain('- Rate limits: signups by your [signup-strict] limiter, token links not limited by the package;');
});

it('reports disabled periods and scheduling', function () {
    config()->set(ConfigKey::RetentionUnsubscribedDays->value, null);
    config()->set(ConfigKey::RetentionSchedule->value, null);

    expect(privacyRecord())
        ->toContain('- Addresses that left erased: kept until erased')
        ->toContain('- Not scheduled by the package: run waitlist:prune yourself');
});

it('lists the listeners personal data flows to', function () {
    expect(privacyRecord())->toContain('- No listeners registered.');

    Event::listen(EntryForgotten::class, 'App\Listeners\RemoveFromNewsletter');
    Event::listen(EntryForgotten::class, fn () => null);
    Event::listen(ManageLinkRequested::class, 'App\Listeners\SendManageLink');

    $record = privacyRecord();

    expect($record)
        ->toContain('- EntryForgotten: App\Listeners\RemoveFromNewsletter')
        ->toContain('- ManageLinkRequested: App\Listeners\SendManageLink')
        ->toMatch('/- EntryForgotten: Closure in .*PrivacyCommandTest\.php:\d+/');
});

it('lists every locale of a version', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('newsletter', ['2026-10' => ['en' => 'Also send me the newsletter.', 'de' => 'Schickt mir auch den Newsletter.']]));

    expect(privacyRecord())
        ->toContain('| default | any other list | newsletter | no | 2026-10 (en) | Also send me the newsletter. | yes |')
        ->toContain('| default | any other list | newsletter | no | 2026-10 (de) | Schickt mir auch den Newsletter. | yes |');
});

it('describes a list without wording yet instead of failing', function () {
    config()->set(ConfigKey::Catalog->value, StoredWordingCatalog::class);

    expect(privacyRecord())->toContain('| default | any other list | — | — | — | The purpose [waitlist] of the waitlist [default/*] has no wording. | — |');
});

it('prints wording as stored, in one table row', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['2026-10' => "Email me <info>when</info>\nearly access opens."]));

    expect(privacyRecord())->toContain('| Email me <info>when</info> early access opens. |');
});
