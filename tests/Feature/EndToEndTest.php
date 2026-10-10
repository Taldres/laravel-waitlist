<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\EntryStatus;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Models\WaitlistActivity;

beforeEach(function () {
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);
    config()->set(ConfigKey::StoreIp->value, true);
    config()->set(ConfigKey::StoreUserAgent->value, true);
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string', 'max:50']]));

    require __DIR__.'/../../routes/waitlist.php';
});

it('runs a whole life on the list over HTTP and leaves no trace of the person', function () {
    $tokens = [];
    Event::listen(EntrySubscribed::class, function (EntrySubscribed $event) use (&$tokens): void {
        $tokens = ['confirm' => $event->confirmToken, 'unsubscribe' => $event->unsubscribeToken];
    });
    Event::listen(ManageLinkRequested::class, function (ManageLinkRequested $event) use (&$tokens): void {
        $tokens['manage'] = $event->manageToken;
    });

    $form = $this->getJson('/waitlist/purposes?list=beta')->assertOk()->json('data');
    $shown = collect($form)->mapWithKeys(fn (array $purpose) => [$purpose['purpose'] => $purpose['version']])->all();

    $this->withHeader('User-Agent', 'TestBrowser')
        ->postJson('/waitlist', ['email' => 'Jane@Example.com', 'list' => 'beta', 'purposes' => $shown, 'metadata' => ['source' => 'footer']])
        ->assertStatus(202);

    $this->postJson("/waitlist/confirm/{$tokens['confirm']}")->assertOk()->assertJsonPath('data.status', EntryStatus::Confirmed->value);

    // An unsubscribe token cannot open the preference page; it can only request a manage link by mail.
    $this->getJson("/waitlist/manage/{$tokens['unsubscribe']}")->assertNotFound();
    $this->postJson('/waitlist/manage-link', ['token' => $tokens['unsubscribe']])->assertStatus(202);

    $manage = "/waitlist/manage/{$tokens['manage']}";

    $this->getJson($manage)->assertJsonPath('data.purposes', ['waitlist', 'newsletter']);

    $this->post("/waitlist/unsubscribe/{$tokens['unsubscribe']}?purpose=newsletter", ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->getJson($manage)->assertJsonPath('data.purposes', ['waitlist']);

    $this->putJson("{$manage}/purposes", ['purposes' => $shown])->assertJsonPath('data.purposes', ['waitlist', 'newsletter']);

    $this->postJson("{$manage}/data")
        ->assertJsonPath('email', 'jane@example.com')
        ->assertJsonPath('metadata.source', 'footer')
        ->assertJsonPath('activity.0.user_agent', 'TestBrowser');

    $this->postJson("{$manage}/erase", ['confirm' => true])->assertOk();

    $raw = collect(['waitlist_entries', 'waitlist_subscriptions', 'waitlist_consents', 'waitlist_activity'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()]);

    expect($raw['waitlist_entries'])->toBeEmpty()
        ->and($raw['waitlist_subscriptions'])->toBeEmpty()
        ->and($raw['waitlist_consents'])->toBeEmpty()
        ->and($raw['waitlist_activity'])->not->toBeEmpty()
        ->and($raw->toJson())->not->toContain('jane@example.com')
        ->and(WaitlistActivity::query()->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('occurred_at')->orWhereNotNull('waitlist_entry_id')->exists())->toBeFalse();
});
