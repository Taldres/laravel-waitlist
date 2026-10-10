<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesAsJson
{
    /**
     * Always JSON: the routes run without a session, so a redirect back would
     * lose the errors.
     *
     * @param  array<string, mixed>  $rules
     * @return array<array-key, mixed>
     *
     * @throws ValidationException
     */
    protected function validateAsJson(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator, new JsonResponse([
                'message' => (new ValidationException($validator))->getMessage(),
                'errors' => $validator->errors()->messages(),
            ], 422));
        }

        return $validator->validated();
    }
}
