<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Taldres\Waitlist\Auth\WaitlistGate;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\WaitlistAction;
use Taldres\Waitlist\Http\Rules\PurposeChoice;

class SubscribeRequest extends WaitlistRequest
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $resolvedFields = null;

    protected bool $fieldsResolved = false;

    protected function action(): WaitlistAction
    {
        return WaitlistAction::Subscribe;
    }

    /**
     * The project decides which fields the request may carry, so it and the
     * useWaitlist gate come first. A signup that brings wording also needs the
     * gate's RegisterWording.
     */
    public function authorize(): Response
    {
        $response = parent::authorize();

        if ($response->denied() || ! $this->sendsWording()) {
            return $response;
        }

        return WaitlistGate::inspect($this, $this->project(), WaitlistAction::RegisterWording, $this->effectiveList());
    }

    public function sendsWording(): bool
    {
        $purposes = $this->input('purposes');

        return is_array($purposes) && Arr::some($purposes, fn (mixed $choice): bool => is_array($choice) && array_key_exists('text', $choice));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
            // A "*" list accepts any name a client sends, so keep it plain.
            'list' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:'.ProjectDefinition::NAME_PATTERN],
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
