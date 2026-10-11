<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Tests\Fixtures;

use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\ProjectPeriods;

final class ArrayProjectCatalog implements ProjectCatalog
{
    public function policy(string $project, string $list): ?ListPolicy
    {
        return $project === 'shop' && $list === 'restock'
            ? new ListPolicy('shop', 'restock', 'restock', [], doubleOptIn: false)
            : null;
    }

    public function versions(string $project, string $purpose): array
    {
        return $project === 'shop' && $purpose === 'restock' ? ['v1' => 'Tell me when it is back in stock.'] : [];
    }

    public function fields(string $project, string $list): array
    {
        return $project === 'shop' && $list === 'restock' ? ['sku' => ['required', 'string', 'max:20']] : [];
    }

    public function urlPattern(string $project, string $action): ?string
    {
        return $project === 'shop' && $action === Page::Confirm->value ? 'https://shop.test/confirm/{token}' : null;
    }

    public function manageLinks(string $project): bool
    {
        return true;
    }

    public function origins(string $project): array
    {
        return [];
    }

    public function periods(string $project): ProjectPeriods
    {
        return new ProjectPeriods;
    }

    public function projects(): array
    {
        return ['shop'];
    }

    public function lists(string $project): array
    {
        return $project === 'shop' ? ['restock'] : [];
    }
}
