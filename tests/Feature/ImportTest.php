<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Enums\SubscribeOutcome;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryImported;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\WordingMismatchException;
use Taldres\Waitlist\Exceptions\WordingNotAcceptedException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;

function importCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/waitlist-import-test-'.bin2hex(random_bytes(4)).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
});

describe('an import with the time of consent', function () {
    it('confirms the address without a mail, keeping the original time and recording the source', function () {
        Event::fake([EntrySubscribed::class, EntryConfirmed::class, EntryImported::class]);

        $result = Waitlist::for('beta')->import(
            'Person@Example.com',
            ['waitlist' => '2026-09', 'newsletter' => ['version' => '2026-10']],
            'mailchimp-2026-09',
            Carbon::parse('2025-03-01 08:30:00'),
        );

        expect($result)->not->toBeNull()
            ->and($result->outcome)->toBe(SubscribeOutcome::Started)
            ->and($result->entry->status)->toBe(EntryStatus::Confirmed)
            ->and($result->subscription->confirmed_at->toDateTimeString())->toBe('2026-10-14 12:00:00')
            ->and($result->subscription->confirmation_count)->toBe(0)
            ->and($result->subscription->confirm_token_hash)->toBeNull();

        $consents = $result->subscription->consents()->orderBy('id')->get();

        expect($consents->pluck('purpose')->all())->toBe(['waitlist', 'newsletter'])
            ->and($consents->pluck('version')->all())->toBe(['2026-09', '2026-10'])
            ->and($consents[0]->text)->toBe('Earlier wording.')
            ->and($consents->map(fn ($consent) => $consent->granted_at->toDateTimeString())->all())->toBe(['2025-03-01 08:30:00', '2025-03-01 08:30:00']);

        expect(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::Imported, ActivityType::Confirmed])
            ->and(WaitlistActivity::query()->where('type', ActivityType::Imported)->sole()->reference)->toBe('mailchimp-2026-09');

        Event::assertNotDispatched(EntrySubscribed::class);
        Event::assertNotDispatched(EntryConfirmed::class);
        Event::assertDispatchedTimes(EntryImported::class, 1);
        Event::assertDispatched(EntryImported::class, fn (EntryImported $event) => $event->source === 'mailchimp-2026-09'
            && $event->entry->is($result->entry)
            && $event->subscription->is($result->subscription));

        expect(Waitlist::for('beta')->find('person@example.com')->hasConsentFor('newsletter'))->toBeTrue()
            ->and(Waitlist::recipients('newsletter')->count())->toBe(1);

        assertWaitlistInvariants();
    });

    it('shows the source and the original time in an access request', function () {
        Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'old CRM', Carbon::parse('2024-05-06 07:08:09'));

        $data = Waitlist::allProjects()->personalData('person@example.com')->sole()->toArray();

        expect($data['subscriptions'][0]['consents'][0]['granted_at'])->toBe('2024-05-06 07:08:09')
            ->and(collect($data['activity'])->firstWhere('type', 'imported'))->toMatchArray([
                'reference' => 'old CRM',
                'occurred_at' => '2026-10-14 12:00:00',
            ]);

        $this->artisan('waitlist:show', ['email' => 'person@example.com', '--pretty' => true])
            ->expectsOutputToContain('Granted: 2024-05-06 07:08:09')
            ->expectsOutputToContain('imported  [old CRM]')
            ->assertSuccessful();
    });

    it('stores a time with an offset in the application time zone', function () {
        $result = Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-03-01T10:00:00+02:00'));

        expect($result->subscription->consents->sole()->granted_at->toDateTimeString())->toBe('2025-03-01 08:00:00');
    });

    it('confirms on a list without double opt-in too, and counts as a confirmation in reports', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('open', purpose: 'waitlist')->doubleOptIn(false));

        Waitlist::for('open')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01'));

        expect(Waitlist::for('open')->report()->confirmedOn(now()))->toBe(1)
            ->and(Waitlist::for('open')->report()->totals()->of(ActivityType::Imported))->toBe(1);
    });

    it('refuses a time in the future or before 1970', function (string $time) {
        Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse($time));
    })->with(['2026-10-14 12:00:01', '1969-12-31 23:59:59'])->throws(InvalidArgumentException::class);
});

describe('an import without the time of consent', function () {
    it('leaves the address waiting for the confirmation mail', function () {
        $requests = captureRequests();
        Event::fake([EntryImported::class, EntryConfirmed::class]);

        $result = Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'paper forms');

        expect($result->outcome)->toBe(SubscribeOutcome::Started)
            ->and($result->entry->status)->toBe(EntryStatus::Pending)
            ->and($result->subscription->consents->sole()->granted_at->toDateTimeString())->toBe('2026-10-14 12:00:00')
            ->and($requests)->toHaveCount(1)
            ->and($requests[0]->isNewCycle)->toBeTrue()
            ->and($requests[0]->confirmToken)->not->toBeNull();

        expect(activityTypes())->toBe([ActivityType::Subscribed, ActivityType::ConfirmationRequested, ActivityType::Imported]);

        Event::assertNotDispatched(EntryImported::class);

        Waitlist::confirm($requests[0]->confirmToken);

        expect($result->entry->refresh()->status)->toBe(EntryStatus::Confirmed);

        assertWaitlistInvariants();
    });

    it('holds the mail back where the address already waits on too many lists', function () {
        config()->set(ConfigKey::MaxPendingPerAddress->value, 1);
        $requests = captureRequests();

        Waitlist::for('first')->add('person@example.com', waitlistConsent());
        $result = Waitlist::for('second')->import('person@example.com', waitlistConsent(), 'crm');

        expect($result->outcome)->toBe(SubscribeOutcome::ConfirmationDeferred)
            ->and($requests)->toHaveCount(1);
    });

    it('needs the time on a list without double opt-in, where nobody would confirm', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('open', purpose: 'waitlist')->doubleOptIn(false));

        Waitlist::for('open')->import('person@example.com', waitlistConsent(), 'crm');
    })->throws(InvalidArgumentException::class, 'has no double opt-in');
});

it('passes on a unique violation of a listener after the commit, rather than calling the address known', function () {
    Event::listen(EntryImported::class, fn () => throw new UniqueConstraintViolationException('testing', 'insert into elsewhere', [], new Exception('duplicate')));

    expect(fn () => Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01')))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(Waitlist::for('beta')->has('person@example.com'))->toBeTrue();
});

describe('what an import leaves alone', function () {
    it('skips an address on the list in any status, so a rerun never duplicates or brings back anyone', function () {
        Waitlist::for('beta')->add('pending@example.com', waitlistConsent());
        Waitlist::for('beta')->import('confirmed@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01'));
        Waitlist::for('beta')->import('left@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01'));
        Waitlist::for('beta')->unsubscribe('left@example.com');

        $requests = captureRequests();
        Event::fake([EntryImported::class]);

        foreach (['pending@example.com', 'CONFIRMED@example.com', 'left@example.com'] as $email) {
            expect(Waitlist::for('beta')->import($email, waitlistConsent(), 'crm', Carbon::parse('2025-01-01')))->toBeNull()
                ->and(Waitlist::for('beta')->import($email, waitlistConsent(), 'crm'))->toBeNull();
        }

        expect(WaitlistEntry::query()->count())->toBe(3)
            ->and(Waitlist::for('beta')->find('left@example.com')->status)->toBe(EntryStatus::Unsubscribed)
            ->and($requests)->toHaveCount(0);

        Event::assertNotDispatched(EntryImported::class);
    });

    it('adds an address that is on another list', function () {
        Waitlist::for('alpha')->add('person@example.com', waitlistConsent());

        expect(Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01')))->not->toBeNull();
    });
});

describe('what an import accepts', function () {
    it('takes only versions the catalog holds, never text', function (array $purposes, string $exception) {
        expect(fn () => Waitlist::for('beta')->import('person@example.com', $purposes, 'crm', Carbon::parse('2025-01-01')))->toThrow($exception);

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'unknown version' => [['waitlist' => '2020-01'], UnknownPurposeException::class],
        'unknown purpose' => [['waitlist' => '2026-10', 'sms' => '2026-10'], UnknownPurposeException::class],
        'text' => [['waitlist' => ['version' => '2026-10', 'text' => 'Anything.']], WordingNotAcceptedException::class],
        'no primary purpose' => [['newsletter' => '2026-10'], MissingConsentException::class],
    ]);

    it('checks a hash it is given', function () {
        $hash = hash('sha256', 'Email me when early access opens.');

        expect(fn () => Waitlist::for('beta')->import('person@example.com', ['waitlist' => ['version' => '2026-10', 'hash' => str_repeat('0', 64)]], 'crm'))
            ->toThrow(WordingMismatchException::class)
            ->and(Waitlist::for('beta')->import('person@example.com', ['waitlist' => ['version' => '2026-10', 'hash' => strtoupper($hash)]], 'crm'))->not->toBeNull();
    });

    it('asks for no hash: there was no form', function () {
        config()->set(ConfigKey::WordingRequireHash->value, true);

        expect(Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01')))->not->toBeNull();
    });

    it('refuses an invalid address without naming it', function () {
        Waitlist::for('beta')->import('not-an-address', waitlistConsent(), 'crm');
    })->throws(InvalidEmailException::class, 'The email address is not valid.');

    it('needs a source', function (string $source) {
        Waitlist::for('beta')->import('person@example.com', waitlistConsent(), $source);
    })->with(['', '   ', str_repeat('x', 256)])->throws(InvalidArgumentException::class);

    it('stays inside its project', function () {
        Waitlist::define('acme', function (ProjectDefinition $project): void {
            $project->purpose('waitlist', ['2026-10' => 'Tell me when Acme opens.']);
            $project->list('beta', purpose: 'waitlist');
        });

        Waitlist::for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01'));
        $result = Waitlist::project('acme')->for('beta')->import('person@example.com', waitlistConsent(), 'crm', Carbon::parse('2025-01-01'));

        expect($result->entry->project)->toBe('acme')
            ->and($result->subscription->consents->sole()->text)->toBe('Tell me when Acme opens.')
            ->and(WaitlistEntry::query()->count())->toBe(2);
    });
});

it('clears the source on erasure and lists it for the record of processing until then', function () {
    Waitlist::for('beta')->import('a@example.com', waitlistConsent(), 'mailchimp-2026-09', Carbon::parse('2025-01-01'));
    Waitlist::for('beta')->import('b@example.com', waitlistConsent(), 'mailchimp-2026-09', Carbon::parse('2025-01-01'));

    $this->artisan('waitlist:privacy')
        ->expectsOutputToContain('[default] mailchimp-2026-09: 2 entries')
        ->assertSuccessful();

    Waitlist::allProjects()->forget('a@example.com');
    Waitlist::allProjects()->forget('b@example.com');

    expect(WaitlistActivity::query()->where('type', ActivityType::Imported)->whereNotNull('reference')->count())->toBe(0)
        ->and(WaitlistActivity::query()->where('type', ActivityType::Imported)->count())->toBe(2);

    $this->artisan('waitlist:privacy')
        ->expectsOutputToContain('Imports: none held')
        ->assertSuccessful();
});

describe('waitlist:import', function () {
    it('imports a file with the time of consent and reports failed lines without their address', function () {
        Event::fake([EntrySubscribed::class, EntryImported::class]);
        Waitlist::for('beta')->add('known@example.com', waitlistConsent());

        $file = importCsv("\u{FEFF}Email,Purposes,consented_at,locale\n"
            ."a@example.com,\"waitlist:2026-10, newsletter:2026-10\",2025-03-01 08:30:00,\n"
            ."secret-not-an-address,waitlist:2026-10,2025-03-01,\n"
            ."\n"
            ."known@example.com,waitlist:2026-10,2025-03-01,\n"
            ."b@example.com,waitlist:2026-10,tomorrow,\n"
            ."c@example.com,waitlist:2020-01,2025-03-01,\n"
            ."d@example.com,waitlist:2026-10,2025-03-01T10:00:00+02:00,\n");

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'mailchimp', '--list' => 'beta', '--with-consent' => true])
            ->expectsOutputToContain('Line 3: email: not a valid address.')
            ->expectsOutputToContain('Line 6: consented_at: not a time of consent')
            ->expectsOutputToContain('Line 7: purposes, locale:')
            ->doesntExpectOutputToContain('secret-not-an-address')
            ->doesntExpectOutputToContain('b@example.com')
            ->expectsOutputToContain('Imported 2, skipped 1 already on the list, 3 failed.')
            ->assertFailed();

        expect(Waitlist::for('beta')->find('a@example.com')->hasConsentFor('newsletter'))->toBeTrue()
            ->and(Waitlist::for('beta')->find('d@example.com')->currentSubscription->consents->sole()->granted_at->toDateTimeString())->toBe('2025-03-01 08:00:00');

        Event::assertDispatchedTimes(EntryImported::class, 2);

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'mailchimp', '--list' => 'beta', '--with-consent' => true])
            ->expectsOutputToContain('Imported 0, skipped 3 already on the list, 3 failed.');

        expect(WaitlistEntry::query()->count())->toBe(3);
    });

    it('requests confirmations a limited number at a time, and resumes where it stopped', function () {
        $requests = captureRequests();
        $file = importCsv("email,purposes,list\na@example.com,waitlist:2026-10,alpha\nb@example.com,waitlist:2026-10,beta\nc@example.com,waitlist:2026-10,\n");

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'paper forms', '--request-confirmation' => true, '--list' => 'gamma', '--limit' => '2'])
            ->expectsOutputToContain('Stopped at line 4 after 2 imports')
            ->expectsOutputToContain('php artisan waitlist:import '.escapeshellarg($file).' --source='.escapeshellarg('paper forms').' --request-confirmation --list='.escapeshellarg('gamma').' --project='.escapeshellarg('default').' --limit=2 --from-line=4')
            ->expectsOutputToContain('Imported 2, skipped 0 already on the list, 0 failed.')
            ->assertSuccessful();

        // Erased between the batches: the resumed run must not bring it back.
        Waitlist::allProjects()->forget('a@example.com');

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'paper forms', '--request-confirmation' => true, '--list' => 'gamma', '--limit' => '2', '--from-line' => '4'])
            ->expectsOutputToContain('Imported 1, skipped 0 already on the list, 0 failed.')
            ->assertSuccessful();

        expect($requests)->toHaveCount(3)
            ->and(Waitlist::allProjects()->exists('a@example.com'))->toBeFalse()
            ->and(WaitlistEntry::query()->orderBy('list')->pluck('list')->all())->toBe(['beta', 'gamma'])
            ->and(WaitlistEntry::query()->where('status', EntryStatus::Pending)->count())->toBe(2);
    });

    it('never prints a value from the file, also from a row whose columns are mixed up', function () {
        $file = importCsv("email,purposes,list,consented_at\n"
            ."ok@example.com,waitlist:2026-10,leak-one@example.com,2025-03-01\n"
            ."ok@example.com,leak-two@example.com,beta,2025-03-01\n"
            ."ok@example.com,leak-three@example.com:1,beta,2025-03-01\n"
            ."ok@example.com,waitlist:2026-10,beta,leak-four@example.com\n");

        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'crm', '--with-consent' => true])
            ->expectsOutputToContain('Line 2: list: not a list of the project.')
            ->expectsOutputToContain('Line 3: purposes: write each purpose as purpose:version.')
            ->expectsOutputToContain('Line 4: purposes, locale:')
            ->expectsOutputToContain('Line 5: consented_at:')
            ->doesntExpectOutputToContain('leak-')
            ->assertFailed();
    });

    it('refuses times that are not the source\'s own, and purposes named twice', function (string $purposes, string $time, string $message) {
        $file = importCsv("email,purposes,consented_at\nperson@example.com,\"{$purposes}\",{$time}\n");

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'crm', '--list' => 'beta', '--with-consent' => true])
            ->expectsOutputToContain("Line 2: {$message}")
            ->assertFailed();

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'rolled-over date' => ['waitlist:2026-10', '2026-02-31', 'consented_at: not a time'],
        'rolled-over time' => ['waitlist:2026-10', '2025-03-01 24:30', 'consented_at: not a time'],
        'relative suffix' => ['waitlist:2026-10', '2025-03-01 +1 day', 'consented_at: not a time'],
        'future' => ['waitlist:2026-10', '2026-10-15', 'consented_at: lies in the future'],
        'purpose twice' => ['waitlist:2026-10 waitlist:2026-09', '2025-03-01', 'purposes: a purpose is named twice.'],
    ]);

    it('refuses to start without a mode, a source, the columns it needs, or with columns it does not take', function (array $options, string $csv, string $message) {
        $this->artisan('waitlist:import', ['file' => importCsv($csv), ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with([
        'no mode' => [['--source' => 'crm', '--list' => 'beta'], "email,purposes\na@example.com,waitlist:2026-10\n", 'Pass either --with-consent or --request-confirmation.'],
        'both modes' => [['--source' => 'crm', '--list' => 'beta', '--with-consent' => true, '--request-confirmation' => true], "email,purposes\n", 'Pass either'],
        'no source' => [['--list' => 'beta', '--with-consent' => true], "email,purposes\n", 'Pass --source'],
        'no time column' => [['--source' => 'crm', '--list' => 'beta', '--with-consent' => true], "email,purposes\na@example.com,waitlist:2026-10\n", 'lacks the columns consented_at'],
        'no list' => [['--source' => 'crm', '--request-confirmation' => true], "email,purposes\na@example.com,waitlist:2026-10\n", 'or pass --list'],
        'other columns' => [['--source' => 'crm', '--list' => 'beta', '--request-confirmation' => true], "email,purposes,name\na@example.com,waitlist:2026-10,Ann\n", 'it does not take: name.'],
        'unknown project' => [['--source' => 'crm', '--list' => 'beta', '--request-confirmation' => true, '--project' => 'nope'], "email,purposes\n", 'is not defined'],
        'bad limit' => [['--source' => 'crm', '--list' => 'beta', '--request-confirmation' => true, '--limit' => '0'], "email,purposes\n", '--limit takes'],
    ]);

    it('stops on a setup error and prints where to resume, without the row', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', []), lists: true);
        $file = importCsv("email,purposes,list\nfirst@example.com,waitlist:2026-10,leak@example.com\nsecond@example.com,waitlist:2026-10,beta\n");

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'crm', '--request-confirmation' => true])
            ->expectsOutputToContain('Line 2: the import stopped on a setup error (MissingWordingException)')
            ->expectsOutputToContain('--from-line=2')
            ->doesntExpectOutputToContain('leak@example.com')
            ->doesntExpectOutputToContain('Line 3')
            ->assertFailed();

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('names no value of a first line that is no header row', function () {
        $file = importCsv("leak@example.com,waitlist:2026-10,beta\nother@example.com,waitlist:2026-10,beta\n");

        $this->artisan('waitlist:import', ['file' => $file, '--source' => 'crm', '--list' => 'beta', '--request-confirmation' => true])
            ->expectsOutputToContain('it does not take: column 1, column 2, beta.')
            ->doesntExpectOutputToContain('leak@example.com')
            ->assertFailed();

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('refuses a file it cannot read', function () {
        $this->artisan('waitlist:import', ['file' => sys_get_temp_dir().'/waitlist-import-missing.csv', '--source' => 'crm', '--list' => 'beta', '--request-confirmation' => true])
            ->expectsOutputToContain('cannot be read')
            ->assertFailed();
    });
});
