<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Taldres\Waitlist\Http\Requests\Concerns\FailsValidationAsJson;
use Taldres\Waitlist\Http\Rules\PurposeChoice;

#[FailOnUnknownFields(false)]
class SyncPurposesRequest extends FormRequest
{
    use FailsValidationAsJson;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purposes' => ['required', 'array', 'max:20'],
            'purposes.*' => ['required', new PurposeChoice],
        ];
    }
}
