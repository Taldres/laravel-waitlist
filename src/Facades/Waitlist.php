<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Facades;

use Illuminate\Support\Facades\Facade;
use Taldres\Waitlist\WaitlistManager;

/**
 * @method static \Taldres\Waitlist\WaitlistManager define(string|\Closure $project, ?\Closure $callback = null)
 * @method static \Taldres\Waitlist\ScopedWaitlist for(string $list)
 * @method static \Taldres\Waitlist\ProjectWaitlist project(string $project)
 * @method static bool hasProject(string $project)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null caller(\Illuminate\Http\Request $request)
 * @method static \Taldres\Waitlist\AllProjectsWaitlist allProjects()
 * @method static \Taldres\Waitlist\Support\SubscribeResult subscribe(string $list, string $email, array<string, string|array{version: string, locale?: string|null, hash?: string}> $purposes, array<string, mixed> $metadata = [], ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static list<\Taldres\Waitlist\Support\PurposeWording> purposes(string $list, ?string $locale = null)
 * @method static int registerWording(string $purpose, string $version, string|array<string, string> $wording)
 * @method static int retireWording(string $purpose, string $version)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry|null resendConfirmation(string $list, string $email, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry confirm(string $plainToken, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry unsubscribe(string $plainToken, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry grantConsent(string $plainToken, string $purpose, string $version, ?string $locale = null, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry withdrawConsent(string $plainToken, string $purpose, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static void confirmationMailed(\Taldres\Waitlist\Models\WaitlistSubscription $subscription, string $reference, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static bool confirmationFailed(\Taldres\Waitlist\Models\WaitlistSubscription $subscription, ?string $reference = null, ?\Taldres\Waitlist\Support\RequestContext $context = null)
 * @method static \Taldres\Waitlist\Models\WaitlistSubscription|null findByConfirmToken(string $plainToken)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry|null findByUnsubscribeToken(string $plainToken)
 * @method static \Taldres\Waitlist\Models\WaitlistEntry|null findByManageToken(string $plainToken)
 * @method static \Taldres\Waitlist\Support\UnsubscribeToken unsubscribeToken(\Taldres\Waitlist\Models\WaitlistEntry $entry)
 * @method static \Taldres\Waitlist\Support\ManageLink manageLink(\Taldres\Waitlist\Models\WaitlistEntry $entry)
 * @method static bool requestManageLink(string $unsubscribeToken)
 * @method static string|null unsubscribeUrl(\Taldres\Waitlist\Models\WaitlistEntry $entry, ?string $purpose = null)
 * @method static array<string, string> listUnsubscribeHeaders(\Taldres\Waitlist\Models\WaitlistEntry $entry, ?string $purpose = null)
 * @method static bool exists(string $email, ?string $list = null)
 * @method static \Illuminate\Support\Collection<int, \Taldres\Waitlist\Models\WaitlistEntry> findByEmail(string $email, ?string $list = null)
 * @method static \Illuminate\Support\LazyCollection<int, \Taldres\Waitlist\Support\Recipient> recipients(string $purpose)
 * @method static \Taldres\Waitlist\Reporting\WaitlistReport report()
 * @method static int forget(string $email, ?string $list = null)
 * @method static \Illuminate\Support\Collection<int, \Taldres\Waitlist\Support\PersonalData> personalData(string $email, ?string $list = null)
 * @method static \Taldres\Waitlist\WaitlistManager verifySpamUsing(?\Closure $callback)
 * @method static \Taldres\Waitlist\WaitlistManager encryptUsing(?\Illuminate\Contracts\Encryption\Encrypter $encrypter)
 * @method static \Illuminate\Contracts\Encryption\Encrypter encrypter()
 * @method static \Illuminate\Contracts\Encryption\Encrypter|null customEncrypter()
 *
 * @see WaitlistManager
 */
class Waitlist extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WaitlistManager::class;
    }
}
