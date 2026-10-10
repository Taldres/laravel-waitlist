<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\StoredWordingCatalog;
use Taldres\Waitlist\Tests\TestCase;

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

it('names the limit of servers calling for a project and where their visitors come from', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);

    expect(privacyRecord())
        ->not->toContain('servers calling for a project')
        ->not->toContain('header of servers');

    config()->set(ConfigKey::AuthenticationRequired->value, true);

    expect(privacyRecord())
        ->toContain('- Rate limits for servers calling for a project: signups 120 per minute and server'.PHP_EOL)
        ->not->toContain('header of servers');

    config()->set(ConfigKey::ClientIpHeader->value, 'X-Visitor-Ip');

    expect(privacyRecord())
        ->toContain('signups 120 per minute and server, and 10 per minute and visitor')
        ->toContain("- A visitor's address is read from the `X-Visitor-Ip` header of servers calling for a project, never of guests");
});

it('leaves out the limit of servers where your limiter replaces the package\'s, and where the routes are off', function () {
    config()->set(ConfigKey::ClientIpHeader->value, 'X-Visitor-Ip');

    expect(privacyRecord())->not->toContain('servers calling for a project');

    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::SignupLimiter->value, 'signup-strict');

    expect(privacyRecord())
        ->not->toContain('Rate limits for servers calling for a project')
        ->toContain('header of servers calling for a project');
});

it('needs the limit of servers only where it describes it', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::CallerSignupPerMinute->value, 'soon');

    expect(Artisan::call('waitlist:privacy'))->toBe(0);

    config()->set(ConfigKey::AuthenticationRequired->value, true);

    expect(fn () => Artisan::call('waitlist:privacy'))->toThrow(InvalidConfigurationException::class, ConfigKey::CallerSignupPerMinute->value);
});

it('warns about page URLs a recipient could not open', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
        confirm: 'http://localhost:3000/confirm?token={token}',
        unsubscribe: 'http://example.com/unsubscribe?token={token}',
        manage: '/manage?token={token}',
    ));

    expect(privacyRecord())
        ->toContain('Warning: The confirm link of project [default] points at http://localhost, so a link in a mail may not open for the person.')
        ->toContain('Warning: The unsubscribe link of project [default] does not use https (http://example.com), so')
        ->toContain('Warning: The manage link of project [default] is not an absolute URL, so');
});

it('names APP_URL where the package routes carry the links', function () {
    defineDefaultProject();
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set('app.url', 'http://127.0.0.1:8000');

    expect(privacyRecord())
        ->toContain('Warning: The confirm, unsubscribe, manage link of project [default] points at http://127.0.0.1, from APP_URL, so');

    config()->set('app.url', 'https://api.example.com');

    expect(privacyRecord())->not->toContain('Warning:');
});

it('leaves out what does not apply: the manage link of a project without manage links, and debug and guards while the routes are off', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->manageLinks(false)->urls(
        confirm: 'https://example.com/confirm?token={token}',
        unsubscribe: 'https://example.com/unsubscribe?token={token}',
        manage: 'http://localhost/manage?token={token}',
    ));
    config()->set('app.debug', true);
    config()->set(ConfigKey::AuthenticationGuards->value, ['sanctum']);

    expect(privacyRecord())->not->toContain('Warning:');
});

it('warns when debug is on and guards leave guests in', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set('app.url', 'https://api.example.com');
    config()->set('app.debug', true);
    config()->set(ConfigKey::AuthenticationGuards->value, ['sanctum']);

    expect(privacyRecord())
        ->toContain('Warning: APP_DEBUG is on while the waitlist routes are enabled')
        ->toContain('Warning: authentication.guards names guards but authentication.required is off');

    config()->set('app.debug', false);
    config()->set(ConfigKey::AuthenticationRequired->value, true);

    expect(privacyRecord())->not->toContain('Warning:');
});

it('warns of guards that do not read instead of failing', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set('app.url', 'https://api.example.com');
    config()->set(ConfigKey::AuthenticationGuards->value, 'soon');

    expect(privacyRecord())->toContain('Warning: The '.ConfigKey::AuthenticationGuards->value.' config');
});

it('keeps the warnings out of a record written to a file', function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set('app.debug', true);

    $error = new BufferedOutput;
    $output = new class($error) extends BufferedOutput implements ConsoleOutputInterface
    {
        public function __construct(private OutputInterface $error)
        {
            parent::__construct();
        }

        public function getErrorOutput(): OutputInterface
        {
            return $this->error;
        }

        public function setErrorOutput(OutputInterface $error): void
        {
            $this->error = $error;
        }

        public function section(): ConsoleSectionOutput
        {
            throw new LogicException('Not needed.');
        }
    };

    Artisan::call('waitlist:privacy', outputBuffer: $output);

    expect($output->fetch())->not->toContain('Warning:')
        ->and($error->fetch())->toContain('Warning: APP_DEBUG is on');
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

it('needs no setting it does not describe', function (string $key) {
    config()->set($key, 'soon');

    expect(Artisan::call('waitlist:privacy'))->toBe(0)
        ->and(Artisan::output())->toContain('Waitlist processing record');
})->with([
    'the lifetime of a confirm link' => ConfigKey::ConfirmTokenTtl->value,
    'the cooldown of manage link mails' => ConfigKey::ManageRequestCooldown->value,
]);

it('describes the manage link lifetime and the resend rules it reads', function () {
    config()->set(ConfigKey::ManageTokenTtl->value, 90);
    config()->set(ConfigKey::ResendCooldown->value, 15);
    config()->set(ConfigKey::MaxConfirmations->value, null);
    config()->set(ConfigKey::MaxPendingPerAddress->value, null);

    expect(privacyRecord())
        ->toContain('expires after 90 minutes')
        ->toContain('- Confirmation mails limited to one per 15 minutes')
        ->not->toContain('per cycle')
        ->not->toContain('At most');
});

it('sends access and erasure through the operator where a project has no manage links', function () {
    defineDefaultProject();
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->manageLinks(false)));

    expect(privacyRecord())
        ->toContain('- On request (Art. 17): waitlist:forget, or the person via a manage link sent to their address, except in projects without manage links (acme)')
        ->toContain('expires after 60 minutes; projects without manage links (acme) handle both through you (waitlist:export, waitlist:forget)');

    Artisan::call('waitlist:privacy', ['--project' => 'acme']);

    expect(Artisan::output())
        ->toContain('- On request (Art. 17): waitlist:forget'.PHP_EOL)
        ->toContain('no manage links are sent, so access to the data and erasure go through you (waitlist:export, waitlist:forget)')
        ->not->toContain('expires after');
});

it('leaves a project without lists out of the manage links, such as an undefined default project', function () {
    defineDefaultProject(lists: false);
    Waitlist::define('acme', fn (ProjectDefinition $project) => TestCase::defineTestProject($project->manageLinks(false)));

    expect(privacyRecord())
        ->toContain('- On request (Art. 17): waitlist:forget'.PHP_EOL)
        ->toContain('no manage links are sent, so access to the data and erasure go through you')
        ->not->toContain('except in projects without manage links');
});

it('still fails on a setting it describes that does not read', function (string $key) {
    config()->set($key, 'soon');

    expect(fn () => Artisan::call('waitlist:privacy'))->toThrow(InvalidConfigurationException::class, $key);
})->with([
    ConfigKey::ManageTokenTtl->value,
    ConfigKey::ResendCooldown->value,
    ConfigKey::RetentionRequestMetadataDays->value,
]);
