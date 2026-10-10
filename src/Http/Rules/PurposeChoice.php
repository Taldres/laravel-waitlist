<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accept text only where the useWaitlist gate has allowed RegisterWording.
 */
class PurposeChoice implements ValidationRule
{
    public const array KEYS = ['version', 'locale', 'hash'];

    public const int MAX_TEXT_LENGTH = 10000;

    public function __construct(
        public readonly bool $acceptsWording = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value)) {
            if ($value === '' || strlen($value) > 255) {
                $fail('The :attribute field must be a version of 1 to 255 characters.');
            }

            return;
        }

        if (! is_array($value) || array_is_list($value)) {
            $fail('The :attribute field must be a version, or an object with a version.');

            return;
        }

        $message = $this->problem($value);

        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    protected function problem(array $value): ?string
    {
        $keys = $this->acceptsWording ? [...self::KEYS, 'text'] : self::KEYS;
        $unknown = array_values(array_diff(array_map('strval', array_keys($value)), $keys));
        $version = $value['version'] ?? null;
        $text = $value['text'] ?? null;

        return match (true) {
            $unknown === ['text'] => 'The :attribute field may not carry its text: the wording comes from the catalog; send the version that was shown.',
            $unknown !== [] => 'The :attribute field may only have the keys '.implode(', ', $keys).'.',
            ! is_string($version) || $version === '' || strlen($version) > 255 => 'The :attribute.version field must be a version of 1 to 255 characters.',
            isset($value['locale']) && (! is_string($value['locale']) || strlen($value['locale']) > 35) => 'The :attribute.locale field must be a locale of up to 35 characters.',
            isset($value['hash']) && (! is_string($value['hash']) || preg_match('/^[0-9a-f]{64}$/i', $value['hash']) !== 1) => 'The :attribute.hash field must be the SHA-256 of the wording shown, as 64 hex characters.',
            ! array_key_exists('text', $value) => null,
            ! is_string($text) || trim($text) === '' => 'The :attribute.text field must be the wording that was shown.',
            mb_strlen($text) > self::MAX_TEXT_LENGTH => 'The :attribute.text field may not be longer than '.self::MAX_TEXT_LENGTH.' characters.',
            mb_strlen($version) > 100 => 'The :attribute.version field may not be longer than 100 characters when it comes with its text.',
            default => null,
        };
    }
}
