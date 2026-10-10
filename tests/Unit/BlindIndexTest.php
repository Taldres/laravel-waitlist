<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Taldres\Waitlist\Support\BlindIndex;

function appKeyBytes(string $key): string
{
    return base64_decode(substr($key, 7));
}

it('hashes the normalized address with a subkey of APP_KEY', function () {
    $subkey = hash_hmac('sha256', 'laravel-waitlist:email', appKeyBytes((string) config('app.key')), true);

    expect(BlindIndex::hash(' User@Example.com '))->toBe(hash_hmac('sha256', 'user@example.com', $subkey))
        ->and(BlindIndex::hash('user@example.com'))->not->toBe(hash('sha256', 'user@example.com'));
});

it('offers the hash under the current key first, then under each previous key', function () {
    $old = (string) config('app.key');
    $before = BlindIndex::hash('user@example.com');

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', [$old]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    $hashes = BlindIndex::hashes('user@example.com');

    expect($hashes)->toHaveCount(2)
        ->and($hashes[0])->toBe(BlindIndex::hash('user@example.com'))
        ->and($hashes[0])->not->toBe($before)
        ->and($hashes[1])->toBe($before);
});
