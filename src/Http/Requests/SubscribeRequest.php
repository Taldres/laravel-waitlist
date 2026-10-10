<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Rules\PurposeChoice;
use Taldres\Waitlist\Support\Setting;

class SubscribeRequest extends FormRequest
{
    protected ?string $resolvedProject = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolvedFields = null;

    protected bool $fieldsResolved = false;

    /**
     * The project and the useWaitlist gate come first: the project decides
     * which fields the request may carry, and a refused request answers with
     * the resolver's or the gate's status before its body is looked at. A
     * signup that brings wording also needs the gate's RegisterWording.
     */
    public function authorize(): Response
    {
        $response = WaitlistGate::inspect($this, $this->project(), WaitlistAction::Subscribe, $this->effectiveList());

        if ($response->denied() || ! $this->sendsWording()) {
            return $response;
        }

        return WaitlistGate::inspect($this, $this->project(), WaitlistAction::RegisterWording, $this->effectiveList());
    }

    public function sendsWording(): bool
    {
        $purposes = $this->input('purposes');

        return is_array($purposes) && array_filter($purposes, fn (mixed $choice): bool => is_array($choice) && array_key_exists('text', $choice)) !== [];
    }

    /**
     * The resolver may answer 401 or 403 instead; it runs once per request.
     */
    public function project(): string
    {
        return $this->resolvedProject ??= app(ProjectResolver::class)->resolve($this);
    }

    /**
     * The list the signup is for, as the gate, the fields and the signup see
     * it. A list that is not a string fails validation later; until then the
     * default list stands in.
     */
    public function effectiveList(): string
    {
        $list = $this->input('list');

        return is_string($list) ? $list : Setting::string(ConfigKey::DefaultList->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
            // A "*" list accepts any name a client sends, so keep it plain.
            'list' => ['sometimes', 'string', 'max:100', 'regex:'.ProjectDefinition::NAME_PATTERN],
            'purposes' => ['required', 'array', 'max:20'],
            // Text only gets here past the gate's RegisterWording.
            'purposes.*' => ['required', new PurposeChoice(acceptsWording: true)],
            ...$this->metadataRules(),
        ];
    }

    /**
     * The array rule's message reads like a type error when an extra key is
     * the problem; Laravel's array_keys rule says it better only from 13.24.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $fields = $this->fields();

        if ($fields === null || $fields === []) {
            return [];
        }

        return ['metadata.array' => 'The metadata field must be an array with only these keys: '.implode(', ', array_keys($fields)).'.'];
    }

    public function reject(string $field, string $message): ValidationException
    {
        return $this->invalid(ValidationException::withMessages([$field => $message])->validator);
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
        return new ValidationException($validator, new JsonResponse([
            'message' => (new ValidationException($validator))->getMessage(),
            'errors' => $validator->errors()->messages(),
        ], 422));
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataRules(): array
    {
        $fields = $this->fields();

        // The list does not exist: the controller names the list instead of
        // complaining about fields nobody could know.
        if ($fields === null) {
            return ['metadata' => ['sometimes', 'array']];
        }

        if ($fields === []) {
            return ['metadata' => ['prohibited']];
        }

        $rules = ['metadata' => ['sometimes', 'array:'.implode(',', array_keys($fields))]];

        foreach ($fields as $field => $fieldRules) {
            $rules["metadata.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * The fields the list accepts, built once per request; null when the
     * project has no such list.
     *
     * @return array<string, mixed>|null
     */
    protected function fields(): ?array
    {
        if ($this->fieldsResolved) {
            return $this->resolvedFields;
        }

        $catalog = app(ProjectCatalog::class);
        $project = $this->project();
        $list = $this->effectiveList();

        $this->fieldsResolved = true;

        return $this->resolvedFields = $catalog->policy($project, $list) === null ? null : $catalog->fields($project, $list);
    }
}
