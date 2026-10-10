<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Facades\Waitlist;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('waitlist.routes.enabled', true);
    }

    public function boot(): void
    {
        Waitlist::define(function (ProjectDefinition $project): void {
            $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens. I can unsubscribe at any time.']);
            $project->purpose('newsletter', ['2026-10' => 'Also send me the monthly product newsletter.']);
            $project->list('default', purpose: 'waitlist')->optional('newsletter');
        });
    }
}
