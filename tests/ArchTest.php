<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Taldres\Waitlist')
    ->toUseStrictTypes();

arch('the contracts are interfaces')
    ->expect('Taldres\Waitlist\Contracts')
    ->toBeInterfaces();

arch('the enums are enums')
    ->expect('Taldres\Waitlist\Enums')
    ->toBeEnums();

arch('the models are Eloquent models')
    ->expect('Taldres\Waitlist\Models')
    ->classes
    ->toExtend(Model::class);

arch('the events wait for the commit')
    ->expect('Taldres\Waitlist\Events')
    ->toImplement(ShouldDispatchAfterCommit::class);

arch('the actions need no HTTP request')
    ->expect('Taldres\Waitlist\Actions')
    ->not->toUse([Request::class, 'Taldres\Waitlist\Http']);

arch('only the HTTP layer knows controllers and form requests')
    ->expect([FormRequest::class, 'Illuminate\Routing\Controller'])
    ->not->toBeUsedIn(['Taldres\Waitlist\Actions', 'Taldres\Waitlist\Models', 'Taldres\Waitlist\Support']);
