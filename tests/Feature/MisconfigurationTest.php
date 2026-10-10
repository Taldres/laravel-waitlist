<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Taldres\Waitlist\Actions\PruneEntries;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exceptions\MissingWordingException;
use Taldres\Waitlist\Exceptions\WaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Support\DefinedProjectCatalog;
use Taldres\Waitlist\Support\ListPolicy;

it('throws an InvalidConfigurationException for every setup mistake, never a WaitlistException', function (Closure $mistake) {
    try {
        $mistake();
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(InvalidConfigurationException::class)
            ->toBeInstanceOf(InvalidArgumentException::class)
            ->not->toBeInstanceOf(WaitlistException::class);

        return;
    }

    $this->fail('Nothing was thrown.');
})->with([
    'a primary purpose that is optional too' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'waitlist')->optional('waitlist'));
        app(ProjectCatalog::class)->lists('default');
    }],
    'a list name a signup could not post' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta list', purpose: 'waitlist'));
        app(ProjectCatalog::class)->lists('default');
    }],
    'a mail link without {token}' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->urls(confirm: 'https://app.test/confirm'));
        app(ProjectCatalog::class)->lists('default');
    }],
    'a malformed wording' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', ['v1' => '']));
        app(ProjectCatalog::class)->lists('default');
    }],
    'a field name with a dot' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['address.city' => ['nullable']]));
        app(ProjectCatalog::class)->fields('default', 'beta');
    }],
    'a project name with a space' => [fn () => Waitlist::define('my project', fn (ProjectDefinition $project) => null)],
    'define() without a callback' => [fn () => Waitlist::define('anvil')],
    'a primary purpose without wording' => [function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('beta', purpose: 'undefined'));
        Waitlist::purposes('beta');
    }],
    'a model that is not a waitlist model' => [function () {
        config()->set(ConfigKey::Model->value, stdClass::class);
        Waitlist::exists('user@example.com');
    }],
    'a binding that does not implement its contract' => [function () {
        config()->set(ConfigKey::EmailNormalizer->value, stdClass::class);
        app(EmailNormalizer::class);
    }],
    'guards that are not a list of names' => [function () {
        config()->set(ConfigKey::AuthenticationGuards->value, 'sanctum');
        Waitlist::caller(request());
    }],
    'a negative retention period' => [function () {
        config()->set(ConfigKey::RetentionPendingDays->value, -1);
        PruneEntries::days(ConfigKey::RetentionPendingDays->value);
    }],
    'export columns that may not be exported' => [function () {
        config()->set(ConfigKey::ExportColumns->value, ['email', 'confirm_token_hash']);
        Waitlist::for('beta')->export(sys_get_temp_dir().'/waitlist-misconfiguration-'.bin2hex(random_bytes(4)).'.csv');
    }],
]);

it('throws a MissingWordingException for a list whose primary purpose has no wording', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', []), lists: true);

    Waitlist::for('beta')->add('user@example.com', waitlistConsent());
})->throws(MissingWordingException::class, 'The purpose [waitlist] of the waitlist [default/beta] has no wording.');

it('keeps the input checks of registered wording apart from setup mistakes', function () {
    expect(fn () => Waitlist::registerWording(str_repeat('p', 101), 'v1', 'Text.'))
        ->toThrow(fn (InvalidArgumentException $exception) => expect($exception)->not->toBeInstanceOf(InvalidConfigurationException::class));
});

describe('the processing record', function () {
    it('reports a list without wording as a row', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->purpose('waitlist', []));

        Artisan::call('waitlist:privacy');

        expect(Artisan::output())->toContain('| default | any other list | — | — | — | The purpose [waitlist] of the waitlist [default/*] has no wording. | — |');
    });

    it('fails on any other error instead of writing it into the record', function () {
        app()->bind(ProjectCatalog::class, fn () => new class(app(ProjectDefinitions::class)) extends DefinedProjectCatalog
        {
            public function policy(string $project, string $list): ?ListPolicy
            {
                throw new InvalidArgumentException('The catalog is broken.');
            }
        });

        Artisan::call('waitlist:privacy');
    })->throws(InvalidArgumentException::class, 'The catalog is broken.');
});
