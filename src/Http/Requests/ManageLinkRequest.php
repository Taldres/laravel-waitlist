<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\WaitlistAction;

class ManageLinkRequest extends WaitlistRequest
{
    protected function action(): WaitlistAction
    {
        return WaitlistAction::RequestManageLink;
    }

    /**
     * After the gate and before validation and the spam check, per project, so
     * the answer says nothing about the address and no challenge is spent on a
     * request that cannot succeed.
     */
    public function authorize(): Response
    {
        $response = parent::authorize();

        if ($response->allowed() && ! app(ProjectCatalog::class)->manageLinks($this->project())) {
            throw new HttpResponseException(ApiError::ManageLinksDisabled->response());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
            'list' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
