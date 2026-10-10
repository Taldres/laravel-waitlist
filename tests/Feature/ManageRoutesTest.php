<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Exceptions\ManageLinksDisabledException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistActivity;
use Taldres\Waitlist\Models\WaitlistEntry;

it('exposes nothing while the routes are disabled', function () {
    $tokens = subscribeAndCapture('beta', 'user@example.com');

    $this->getJson("/waitlist/manage/{$tokens['unsubscribe']}")->assertNotFound();
    $this->getJson('/waitlist/purposes')->assertNotFound();
});

it('refuses to mint or request a manage link for a project without them', function () {
    Event::fake([ManageLinkRequested::class]);
    $tokens = subscribeAndCapture('beta', 'user@example.com');
    Waitlist::confirm($tokens['confirm']);
    defineDefaultProject(fn (ProjectDefinition $project) => $project->manageLinks(false));

    expect(fn () => Waitlist::manageLink($tokens['entry']))->toThrow(ManageLinksDisabledException::class, 'The waitlist project [default] offers no manage links.')
        ->and(fn () => Waitlist::requestManageLink($tokens['unsubscribe']))->toThrow(ManageLinksDisabledException::class)
        ->and(fn () => Waitlist::for('beta')->requestManageLink('nobody@example.com'))->toThrow(ManageLinksDisabledException::class)
        ->and(Waitlist::requestManageLink('nope'))->toBeFalse()
        ->and($tokens['entry']->fresh()->manage_token_hash)->toBeNull();

    Event::assertNotDispatched(ManageLinkRequested::class);
});

describe('with routes enabled', function () {
    beforeEach(function () {
        config()->set(ConfigKey::RoutesEnabled->value, true);
        config()->set(ConfigKey::RoutesMiddleware->value, []);

        require __DIR__.'/../../routes/waitlist.php';

        $this->tokens = subscribeAndCapture('beta', 'user@example.com', [...waitlistConsent(), 'newsletter' => '2026-10']);
        Waitlist::confirm($this->tokens['confirm']);
        $this->manage = manageTokenFor($this->tokens['entry']);
    });

    it('serves the wording a form should show', function () {
        $this->getJson('/waitlist/purposes?list=beta')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['purpose' => 'waitlist', 'version' => '2026-10', 'locale' => null, 'text' => 'Email me when early access opens.', 'hash' => hash('sha256', 'Email me when early access opens.'), 'required' => true],
                ['purpose' => 'newsletter', 'version' => '2026-10', 'locale' => null, 'text' => 'Also send me the newsletter.', 'hash' => hash('sha256', 'Also send me the newsletter.'), 'required' => false],
            ]]);

        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

        $this->getJson('/waitlist/purposes?list=secret')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.', 'error' => 'unknown_list']);
    });

    it('shows status and purposes without the address and without changing anything', function () {
        $before = WaitlistActivity::query()->count();

        $this->getJson("/waitlist/manage/{$this->manage}")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Confirmed->value)
            ->assertJsonPath('data.purposes', ['waitlist', 'newsletter'])
            ->assertJsonMissingPath('data.email');

        expect(WaitlistActivity::query()->count())->toBe($before);

        $this->getJson('/waitlist/manage/nope')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invalid token.', 'error' => 'invalid_token']);
    });

    it('sends a browser to the preference page', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(manage: 'https://app.test/preferences/{token}'));

        $this->get("/waitlist/manage/{$this->manage}")
            ->assertRedirect("https://app.test/preferences/{$this->manage}");
    });

    it('builds the manage link', function () {
        $link = Waitlist::manageLink($this->tokens['entry']);

        expect($link->url)->toEndWith("/waitlist/manage/{$link->token}");
    });

    it('opens nothing with the unsubscribe token every mail carries', function () {
        $token = $this->tokens['unsubscribe'];

        $this->getJson("/waitlist/manage/{$token}")->assertNotFound();
        $this->postJson("/waitlist/manage/{$token}/data")->assertNotFound();
        $this->putJson("/waitlist/manage/{$token}/purposes", ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']])->assertNotFound();
        $this->postJson("/waitlist/manage/{$token}/erase", ['confirm' => true])->assertNotFound();

        expect(WaitlistEntry::query()->count())->toBe(1);
    });

    it('lets the person leave from the preference page and keeps the proof of consent', function () {
        $this->postJson("/waitlist/manage/{$this->manage}/unsubscribe")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);

        expect(WaitlistEntry::query()->sole()->latestSubscription->consents)->toHaveCount(2);

        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(unsubscribed: 'https://app.test/goodbye'));
        $this->post("/waitlist/manage/{$this->manage}/unsubscribe")->assertRedirect('https://app.test/goodbye');
    });

    it('answers 410 once the manage link has expired', function () {
        $this->travel(61)->minutes();

        $this->getJson("/waitlist/manage/{$this->manage}")
            ->assertStatus(410)
            ->assertExactJson(['message' => 'This link has expired.', 'error' => 'expired_token']);
        $this->postJson("/waitlist/manage/{$this->manage}/data")->assertStatus(410);
        $this->postJson("/waitlist/manage/{$this->manage}/erase", ['confirm' => true])->assertStatus(410);

        expect(WaitlistEntry::query()->count())->toBe(1);
    });

    it('mails a manage link on request and answers the same either way', function () {
        Event::fake([ManageLinkRequested::class]);

        $responses = [
            $this->postJson('/waitlist/manage-link', ['token' => $this->tokens['unsubscribe']]),
            $this->postJson('/waitlist/manage-link', ['token' => 'nope']),
            $this->postJson('/waitlist/manage-link', ['email' => 'nobody@example.com', 'list' => 'beta']),
            $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta']),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(202)->assertExactJson(['message' => 'Requested.']);
        }

        Event::assertDispatchedTimes(ManageLinkRequested::class, 1);

        $this->postJson('/waitlist/manage-link', [])->assertStatus(422)->assertJsonValidationErrors(['token', 'email']);
    });

    it('runs the spam check when the address is typed in', function () {
        Event::fake([ManageLinkRequested::class]);
        Waitlist::verifySpamUsing(fn () => false);

        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertStatus(422);
        $this->postJson('/waitlist/manage-link', ['token' => $this->tokens['unsubscribe']])->assertStatus(202);

        Waitlist::verifySpamUsing(null);
        Event::assertDispatchedTimes(ManageLinkRequested::class, 1);
    });

    it('refuses manage links for a project that turned them off, the same for every address', function () {
        Event::fake([ManageLinkRequested::class]);
        Waitlist::verifySpamUsing(fn () => false);
        defineDefaultProject(fn (ProjectDefinition $project) => $project->manageLinks(false));

        $refused = ['message' => 'This project offers no manage links.', 'error' => 'manage_links_disabled'];

        $this->postJson('/waitlist/manage-link', ['token' => $this->tokens['unsubscribe']])->assertNotFound()->assertExactJson($refused);
        $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'list' => 'beta'])->assertNotFound()->assertExactJson($refused);
        $this->postJson('/waitlist/manage-link', ['email' => 'nobody@example.com', 'list' => 'beta'])->assertNotFound()->assertExactJson($refused);
        $this->postJson('/waitlist/manage-link', ['token' => 'nope'])->assertStatus(202);

        Waitlist::verifySpamUsing(null);
        Event::assertNotDispatched(ManageLinkRequested::class);
        expect($this->tokens['entry']->fresh()->manage_link_sent_at)->toBeNull();

        // Mailed before the switch, it works until it expires.
        $this->getJson("/waitlist/manage/{$this->manage}")->assertOk();
    });

    it('hands out a copy of the data on POST only', function () {
        $this->getJson("/waitlist/manage/{$this->manage}/data")->assertMethodNotAllowed();

        $this->postJson("/waitlist/manage/{$this->manage}/data")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="waitlist-data.json"')
            ->assertJsonPath('email', 'user@example.com')
            ->assertJsonPath('subscriptions.0.consents.1.purpose', 'newsletter')
            ->assertJsonMissingPath('unsubscribe_token')
            ->assertJsonMissingPath('manage_token');

        $this->postJson('/waitlist/manage/nope/data')->assertNotFound();
    });

    it('applies the purposes a preference page submits', function () {
        $url = "/waitlist/manage/{$this->manage}/purposes";

        $this->putJson($url, ['purposes' => waitlistConsent()])
            ->assertOk()
            ->assertJsonPath('data.purposes', ['waitlist']);

        $this->putJson($url, ['purposes' => [...waitlistConsent(), 'newsletter' => '2026-10']])
            ->assertOk()
            ->assertJsonPath('data.purposes', ['waitlist', 'newsletter']);

        assertWaitlistInvariants();
    });

    it('refuses a submission without the primary purpose or with unknown wording', function (array $purposes) {
        $this->putJson("/waitlist/manage/{$this->manage}/purposes", ['purposes' => $purposes])->assertStatus(422);

        expect(WaitlistEntry::query()->firstOrFail()->purposes)->toBe(['waitlist', 'newsletter']);
    })->with([
        'no primary purpose' => [['newsletter' => '2026-10']],
        'unknown version' => [['waitlist' => '2025-01']],
    ]);

    it('rejects a submission without purposes', function () {
        $this->putJson("/waitlist/manage/{$this->manage}/purposes", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purposes');
    });

    it('answers 409 for a list that was removed since, and still lets the person leave', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('gamma', purpose: 'waitlist'), lists: false);

        $this->putJson("/waitlist/manage/{$this->manage}/purposes", ['purposes' => waitlistConsent()])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This list is no longer available.', 'error' => 'list_unavailable']);
        $this->postJson("/waitlist/unsubscribe/{$this->tokens['unsubscribe']}")
            ->assertOk()
            ->assertJsonPath('data.status', EntryStatus::Unsubscribed->value);
    });

    it('answers 409 once the address has left', function () {
        Waitlist::unsubscribe($this->tokens['unsubscribe']);

        $this->putJson("/waitlist/manage/{$this->manage}/purposes", ['purposes' => waitlistConsent()])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Not subscribed.', 'error' => 'not_subscribed']);
    });

    it('erases only with an explicit confirmation', function () {
        Event::fake([EntryForgotten::class]);

        $url = "/waitlist/manage/{$this->manage}/erase";

        $this->postJson($url)->assertStatus(422)->assertJsonValidationErrors('confirm');

        expect(WaitlistEntry::query()->count())->toBe(1);

        $this->postJson($url, ['confirm' => true])->assertOk();

        expect(WaitlistEntry::query()->count())->toBe(0);
        Event::assertDispatchedTimes(EntryForgotten::class, 1);

        $this->postJson($url, ['confirm' => true])->assertNotFound();
    });

    it('sends a browser on after erasing', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(erased: 'https://app.test/bye'));

        $this->post("/waitlist/manage/{$this->manage}/erase", ['confirm' => '1'])
            ->assertRedirect('https://app.test/bye');
    });

    it('answers validation errors as JSON to a plain form post too', function () {
        $this->post('/waitlist/manage-link', ['email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->post("/waitlist/manage/{$this->manage}/erase")->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->put("/waitlist/manage/{$this->manage}/purposes")->assertStatus(422)->assertJsonValidationErrors('purposes');
    });

    it('sends a browser leaving with a dead link to the landing pages', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(
            invalid: 'https://app.test/oops',
            expired: 'https://app.test/expired',
        ));

        $this->post('/waitlist/manage/nope/unsubscribe')->assertRedirect('https://app.test/oops');

        $this->travel(2)->hours();

        $this->post("/waitlist/manage/{$this->manage}/unsubscribe")->assertRedirect('https://app.test/expired');
        $this->postJson("/waitlist/manage/{$this->manage}/unsubscribe")->assertStatus(410);
    });
});
