<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Taldres\Waitlist\Http\Requests\Concerns\FailsValidationAsJson;

class EraseRequest extends FormRequest
{
    use FailsValidationAsJson;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }
}
