<?php

declare(strict_types=1);

use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\Support\PurposeWording;

beforeEach(function () {
    $this->registry = app(PurposeRegistry::class);
});

it('resolves a named list, or the "*" entry for any other', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->doubleOptIn(false));

    expect($this->registry->policy('default', 'beta'))->toEqual(new ListPolicy('default', 'beta', 'waitlist', [], false))
        ->and($this->registry->policy('default', 'other'))->toEqual(new ListPolicy('default', 'other', 'waitlist', ['newsletter'], true));

    defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist'), lists: false);

    $this->registry->policy('default', 'other');
})->throws(UnknownWaitlistException::class);

it('offers the current wording, primary purpose first', function () {
    expect($this->registry->current($this->registry->policy('default', 'beta')))->toEqual([
        new PurposeWording('waitlist', '2026-10', 'Email me when early access opens.', true),
        new PurposeWording('newsletter', '2026-10', 'Also send me the newsletter.', false),
    ]);
});

it('resolves versions containing dots and numeric keys', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['2026.10' => 'Dotted wording.', 3 => 'Numeric wording.']));

    $policy = $this->registry->policy('default', 'beta');

    expect($this->registry->wording($policy, 'waitlist', '2026.10')->text)->toBe('Dotted wording.')
        ->and($this->registry->resolve($policy, ['waitlist' => 3])[0]->version)->toBe('3');
});

it('requires the primary purpose when resolving a selection', function () {
    $this->registry->resolve($this->registry->policy('default', 'beta'), ['newsletter' => '2026-10']);
})->throws(MissingConsentException::class);

it('rejects purposes and versions it does not know', function (string $purpose, string $version) {
    $this->registry->wording($this->registry->policy('default', 'beta'), $purpose, $version);
})->with([
    'unknown purpose' => ['marketing', '2026-10'],
    'unknown version' => ['waitlist', '2025-01'],
])->throws(UnknownPurposeException::class);

it('reports a misconfigured list when it is used', function (Closure $list) {
    defineDefaultProject($list, lists: false);

    $this->registry->policy('default', 'beta');
})->with([
    'primary also optional' => [fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->optional('waitlist')],
    'purpose without wording' => [fn (ProjectDefinition $project) => $project->list('beta', purpose: 'unknown')],
])->throws(InvalidArgumentException::class);

it('stops offering an optional purpose once every version is retired, and keeps the list working', function () {
    defineDefaultProject(function (ProjectDefinition $project): void {
        $project->purpose('newsletter', []);
        $project->list('beta', purpose: 'waitlist')->optional('newsletter');
    }, lists: false);

    $policy = $this->registry->policy('default', 'beta');

    expect($policy->optional)->toBe([])
        ->and(array_map(fn ($wording) => $wording->purpose, $this->registry->current($policy)))->toBe(['waitlist']);
});
