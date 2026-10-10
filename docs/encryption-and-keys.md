# Encryption and keys

Addresses, metadata, IP and user agent are always encrypted at rest, with
Laravel's [`encrypted` casts](https://laravel.com/docs/eloquent-mutators#encrypted-casting)
on the package's models: Laravel's encrypter, AES-256 with `APP_KEY`. Lookups
never use the address: they run on a keyed hash, HMAC-SHA256 with a subkey of
`APP_KEY`. A database dump or backup without the key holds no readable address.

| Value | Stored as |
| --- | --- |
| Email address, metadata | encrypted |
| IP, user agent, mail reference on the log | encrypted |
| Lookup hash of the address | HMAC-SHA256 with a subkey of `APP_KEY` |
| Unsubscribe token | SHA-256 hash plus an encrypted copy, so mails can carry it again |
| Confirm and manage tokens | SHA-256 hash only |

A keyed hash is pseudonymous, not anonymous: whoever holds the key can test an
address against it. Its job is that a leaked dump holds no readable address. The
full list of what is stored where is in [GDPR in practice](gdpr.md#what-is-stored-where).

## Back up the key

`APP_KEY` protects everything. Back it up apart from the database: without it,
stored addresses can neither be read nor found by email. A separate key for the
waitlist alone would add nothing as long as `APP_KEY` can decrypt the addresses
anyway; see [Your own encrypter](#your-own-encrypter) if you want one regardless.

## Rotating `APP_KEY`

Rotate it [the Laravel way](https://laravel.com/docs/encryption#gracefully-rotating-encryption-keys):
new values are encrypted with the current key, and decryption tries the previous
ones.

1. Put the old key in `APP_PREVIOUS_KEYS` and set the new `APP_KEY`.

   Everything stays readable, email lookups try the hash under every key in
   there, a returning address is matched to its existing entry, and links already
   sent keep working.
2. Run `php artisan waitlist:rekey`.

   It writes every value and lookup hash again under the new key.
3. Remove the old key from `APP_PREVIOUS_KEYS`.

   Only once nothing else needs it: queued jobs and backups made under the old
   key still do.

Without the old key in `APP_PREVIOUS_KEYS`, addresses can neither be read nor
found by email, and each unsubscribe token is replaced by a fresh one the next
time it is needed, so links sent earlier stop working. Retention periods and
`forgetAll()` still erase those entries.

## Finding an address

Since addresses are encrypted, SQL cannot search them. Look them up through the
package, which compares the keyed hash:

```php
Waitlist::exists($email);
Waitlist::findByEmail($email);
WaitlistEntry::query()->forEmail($email);   // for queries of your own
```

## The email normalizer

The `EmailNormalizer` decides which spellings count as the same address; the
default lowercases and trims. It is part of the lookup hash, so choose it before
the first signup and keep it.

```php
// config/waitlist.php
'email_normalizer' => App\Waitlist\GmailAwareNormalizer::class,
```

## Your own encrypter

The package's models follow Laravel's encrypter, so an encrypter set with
`Model::encryptUsing()` applies to them as to every other model of your app.

To keep the waitlist's key apart from the rest of the app, or hold it in a KMS,
pass any implementation of `Illuminate\Contracts\Encryption\Encrypter` in a
service provider's `boot()`. It applies to the package's models only:

```php
use Illuminate\Encryption\Encrypter;
use Taldres\Waitlist\Facades\Waitlist;

Waitlist::encryptUsing(new Encrypter(base64_decode(config('services.waitlist.key')), 'aes-256-gcm'));
```

Its `getKey()` and `getAllKeys()` also make the lookup hash, so they must stay
stable. Values written under another encrypter stay readable only if this one can
decrypt them. Everything above then applies to that encrypter's keys instead, and
so does the responsibility for choosing, storing and rotating them.
`Waitlist::encryptUsing(null)` goes back to Laravel's encrypter.
