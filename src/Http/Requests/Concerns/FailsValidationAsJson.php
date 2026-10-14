<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

trait FailsValidationAsJson
{
    /**
     * A field the rules do not name, such as a honeypot or a captcha token, is
     * no error, also where the app turns on FormRequest::failOnUnknownFields().
     * Laravel before that setting never asks.
     */
    protected function shouldFailOnUnknownFields(): bool
    {
        return false;
    }

    /**
     * Always JSON: the routes run without a session, so a redirect back would
     * lose the errors.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw $this->invalid($validator);
    }

    protected function invalid(Validator $validator): ValidationException
    {
        $exception = new ValidationException($validator);
        $exception->response = new JsonResponse([
            'message' => $exception->getMessage(),
            'errors' => $validator->errors()->messages(),
        ], 422);

        return $exception;
    }
}
