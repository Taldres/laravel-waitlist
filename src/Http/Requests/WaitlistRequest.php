<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Requests\Concerns\FailsValidationAsJson;

/**
 * A request that names a project and a list, and asks the useWaitlist gate
 * before its body is looked at. A field the rules do not name, such as a
 * honeypot or a captcha token, is not an error even where the app fails on
 * unknown fields.
 */
#[FailOnUnknownFields(false)]
abstract class WaitlistRequest extends FormRequest
{
    use FailsValidationAsJson;

    protected ?string $resolvedProject = null;

    abstract protected function action(): WaitlistAction;

    /**
     * The project and the useWaitlist gate come first: a refused request
     * answers with the resolver's or the gate's status before its body is
     * looked at.
     */
    public function authorize(): Response
    {
        return WaitlistGate::inspect($this, $this->project(), $this->action(), $this->effectiveList());
    }

    /**
     * The resolver may answer 401 or 403 instead; it runs once per request.
     */
    public function project(): string
    {
        return $this->resolvedProject ??= app(ProjectResolver::class)->resolve($this);
    }

    /**
     * The list the request is for, as the gate and the action see it. A list
     * that is missing, null or empty means the default list, and one that is
     * not a string fails validation later; until then the default list stands
     * in.
     */
    public function effectiveList(): string
    {
        $list = $this->input('list');

        return is_string($list) && $list !== '' ? $list : WaitlistConfig::defaultList();
    }
}
