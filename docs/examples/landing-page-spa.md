# Example: a landing page or SPA

The situation: one product, so everything lives in the default project. The
signup form, the confirmation page and the preference page are part of a
frontend without a Laravel backend of its own, a static landing page or an SPA
(Nuxt, Next.js, …), and the package routes do the work.

Have Laravel render the pages anyway, with Blade, Livewire or Inertia? Then
[Getting started](../getting-started.md) shows the same flow in your own
controllers, without the package routes.

## Setup

```dotenv
WAITLIST_ROUTES_ENABLED=true
```

- Purposes and lists as in [Getting started](../getting-started.md#2-define-the-purposes-and-the-list).
- A queued listener that sends the confirmation mail, and one for the preference
  page link: [Mail](../mail.md).
- A frontend on another origin than the app: allow it in CORS, see
  [Securing the endpoints](../securing-the-endpoints.md#cors).
- Bot protection on the signup: [Securing the endpoints](../securing-the-endpoints.md#bot-protection-via-spamprotector).

The examples below use Nuxt's `$fetch`; any HTTP client works the same.

## 1. Signup form: render the server's wording

The form shows exactly the text that will be stored as consent, in the visitor's
language, and posts back what it showed per purpose:

```js
const { data } = await $fetch(`/waitlist/purposes?list=beta&locale=${locale}`)
// [{ purpose: 'waitlist', version: '2026-10', locale: 'de', text: '…', hash: '…', required: true },
//  { purpose: 'newsletter', version: '2026-10', locale: 'de', text: '…', hash: '…', required: false }]

const shown = ({ version, locale, hash }) => ({ version, locale, hash })

await $fetch('/waitlist', {
  method: 'POST',
  body: {
    email,
    list: 'beta',
    purposes: {
      waitlist: shown(data[0]),
      ...(newsletter ? { newsletter: shown(data[1]) } : {}),
    },
  },
})
```

The `locale` in the answer is the one actually served, which may be a fallback;
post back that one, not the one you asked for. The `hash` matters when your form
renders its own copy of the text instead of `text`: hash what you show, and a
signup whose text differs from the registered one is refused.

Render the required purpose as the form's own consent text and every optional one
as a separate, unticked checkbox.

More than the address, a company for example, goes under `metadata`, and the
list has to define those fields, see [Fields](../projects.md#fields).

## 2. Point the mail links at your pages

The simplest way is `urls()` in the project's definition, where `{token}` is
replaced. Read the environment through `config()`, never `env()`:

```php
// app/Providers/WaitlistServiceProvider.php, in the definition
$project->urls(
    confirm: config('app.frontend_url').'/waitlist/confirm/{token}',
    unsubscribe: config('app.frontend_url').'/waitlist/leave/{token}',
    manage: config('app.frontend_url').'/waitlist/preferences/{token}',
);
```

The same call takes the pages a browser lands on after posting a form to the
package, see [Pages](../projects.md#pages).

Need more logic (locale, list-specific landing pages)? Bind your own generator:

```php
// app/Waitlist/SpaUrlGenerator.php
namespace App\Waitlist;

use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Models\WaitlistEntry;

class SpaUrlGenerator implements ConfirmationUrlGenerator
{
    public function confirmUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return "https://app.example.com/{$entry->list}/confirm/{$plainToken}";
    }

    public function unsubscribeUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return "https://app.example.com/leave/{$plainToken}";
    }

    public function manageUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return "https://app.example.com/preferences/{$plainToken}";
    }
}
```

```php
// config/waitlist.php
'url_generator' => App\Waitlist\SpaUrlGenerator::class,
```

The package endpoints work the same way if you would rather not write this one:
a `GET` on them only reports state and redirects to your page, which posts the
token back.

## 3. The confirmation page

```vue
<script setup>
const route = useRoute()
const status = ref('confirming')

onMounted(async () => {
  const response = await $fetch(`/waitlist/confirm/${route.params.token}`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    ignoreResponseError: true,
  })
  status.value = response?.data?.status ?? 'error'
})
</script>

<template>
  <p v-if="status === 'confirming'">Confirming…</p>
  <p v-else-if="status === 'confirmed'">You're on the list! 🎉</p>
  <p v-else>This link is invalid or has expired.</p>
</template>
```

## 4. The unsubscribe page

The link in every mail carries the unsubscribe token. It can remove, and it can
ask for a link to the preference page, which goes to the mailbox:

```js
const token = route.params.token
const purpose = route.query.purpose

await $fetch(`/waitlist/unsubscribe/${token}${purpose ? `?purpose=${purpose}` : ''}`, {
  method: 'POST',
  headers: { Accept: 'application/json' },
})

// "Manage your preferences instead": we mail you a link
await $fetch('/waitlist/manage-link', { method: 'POST', body: { token } })
```

## 5. The preference page

Everything the person may do with their own data, behind the manage token from
the mailed link. It expires after an hour; on a `410`, offer to send a new one.

```js
const token = route.params.token
const base = `/waitlist/manage/${token}`
// JSON, not the redirect to this very page that a browser request gets
const headers = { Accept: 'application/json' }

const { data } = await $fetch(base, { headers })  // status, purposes in force

await $fetch(`${base}/purposes`, {                // the complete wanted set
  method: 'PUT',
  headers,
  body: { purposes: { waitlist: '2026-10' } },    // newsletter left out: withdrawn
})

const copy = await $fetch(`${base}/data`, { method: 'POST', headers })   // JSON export

// Leave the list, for someone who came by address and has no unsubscribe link
await $fetch(`${base}/unsubscribe`, { method: 'POST', headers })

await $fetch(`${base}/erase`, { method: 'POST', headers, body: { confirm: true } })
```

The primary purpose must stay in the set; leaving the list is the `unsubscribe`
call. Ask for an explicit confirmation before calling `erase`: it cannot be
undone.
