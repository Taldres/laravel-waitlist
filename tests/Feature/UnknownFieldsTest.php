<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;

beforeEach(function () {
    if (! method_exists(FormRequest::class, 'failOnUnknownFields')) {
        $this->markTestSkipped('This Laravel has no setting to fail on unknown fields.');
    }

    enableWaitlistRoutes();
    FormRequest::failOnUnknownFields();
});

afterEach(function () {
    if (method_exists(FormRequest::class, 'failOnUnknownFields')) {
        FormRequest::failOnUnknownFields(false);
    }
});

it('takes a field no rule names on the signup and the manage link by address', function () {
    $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent(), 'website' => ''])->assertStatus(202);
    $this->postJson('/waitlist/manage-link', ['email' => 'user@example.com', 'website' => ''])->assertStatus(202);
});

it('takes a field no rule names behind a manage token', function () {
    $this->postJson('/waitlist/manage/nope/erase', ['confirm' => true, 'website' => ''])->assertNotFound()->assertJsonPath('error', 'invalid_token');
    $this->putJson('/waitlist/manage/nope/purposes', ['purposes' => waitlistConsent(), 'website' => ''])->assertNotFound()->assertJsonPath('error', 'invalid_token');
});
