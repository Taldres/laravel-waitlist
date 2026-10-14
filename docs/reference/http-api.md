# HTTP API

The package routes are a JSON API for frontends that have no backend of their
own: a static landing page, an SPA, a site on another domain. They are disabled
by default, because installing a package should never silently expose a public
write endpoint:

```dotenv
WAITLIST_ROUTES_ENABLED=true
```

Have a Laravel backend for the pages anyway, with Blade, Livewire or Inertia?
Leave the routes off and call the facade from your controllers;
[Getting started](../getting-started.md) shows both ways side by side.

## Endpoints

| Method | URI | Behavior |
| --- | --- | --- |
| GET | `/waitlist/purposes?list=&locale=` | Current wording per purpose, for the signup form, with the locale it is in. With [`TimeTrapSpamProtector`](../securing-the-endpoints.md#built-in-a-hidden-field-and-a-time-trap) bound, also a `challenge` that the signup or manage link sends back in `X-Waitlist-Challenge`, with `Cache-Control: no-store`; the JS client does not expose it yet |
| GET | `/waitlist/count?list=` | How many people are on a list that [publishes its count](#count-and-position), rounded. `404` with `count_disabled` for a list that does not, `unknown_list` for one the project does not have, `422` naming `list` for a name no signup could post; the project resolver, the origins and the `useWaitlist` gate decide first, as for the wording. |
| POST | `/waitlist` | Subscribe with `email`, `purposes` (`{purpose: version}` or `{purpose: {version, locale, hash}}`; a project's own servers may add `text`, see [Wording sent by your servers](../purposes-and-wording.md#wording-sent-by-your-servers)), optional `list` (`waitlist.default_list` when missing, null or empty) and `metadata` (only the [fields](#metadata) the project and list define). `202` with an identical body for new and known addresses; `422` names the field (`email`, `list`, `purposes`, `metadata`, `metadata.{field}`), always as JSON, or says `Spam check failed.`; the project resolver and the [`useWaitlist` gate](#projects-and-who-may-call) may answer first, e.g. `401`, `403` or `404`. |
| GET | `/waitlist/confirm/{token}` | Reports the token's state. `404` invalid, `410` expired. |
| POST | `/waitlist/confirm/{token}` | Confirm, or answer a request to confirm again ([`ReconfirmationRequested`](../lifecycle.md#asking-confirmed-addresses-again)), which keeps the address on the list. |
| GET | `/waitlist/unsubscribe/{token}` | Reports the entry. `404` invalid. |
| POST | `/waitlist/unsubscribe/{token}[?purpose=]` | Unsubscribe, or withdraw one purpose; including [RFC 8058](https://www.rfc-editor.org/rfc/rfc8058) one-click. |
| GET | `/waitlist/unsubscribe/{token}/position` | The person's [place in line](#count-and-position) on a list that publishes positions. `404` invalid, `404` with `position_disabled` for a list that does not. |
| POST | `/waitlist/unsubscribe/{token}/manage-link` | Mail a manage link to the address behind the unsubscribe token. `202` with an identical body whether or not anything was sent, also for a token that belongs to nobody. A token needs no resolver, gate or spam check, and the route is in the `links` group. `404` with `manage_links_disabled` for a project [without manage links](../projects.md#pages). |
| POST | `/waitlist/manage-link` | Mail a manage link to an `email`, with an optional `list`. `202` with an identical body whether or not anything was sent. The project resolver and the `useWaitlist` gate decide first, then the spam check applies. `404` with `manage_links_disabled` for a project without manage links. |
| GET | `/waitlist/manage/{token}` | Status and purposes in force, for the preference page. `404` invalid, `410` expired. |
| PUT | `/waitlist/manage/{token}/purposes` | Set the purposes; the primary one must be included, else `422`. `409` once the address has left. |
| POST | `/waitlist/manage/{token}/unsubscribe` | Leave this list, keeping the consent records for the configured retention period. |
| POST | `/waitlist/manage/{token}/data` | A JSON copy of everything stored for this entry, i.e. this list. |
| POST | `/waitlist/manage/{token}/erase` | Erase this entry, with `{"confirm": true}`. |

`/manage/…` takes the manage token, `/unsubscribe/…` the unsubscribe token, `/confirm/…` the confirm token. The token routes answer
`{"data": {project, list, status, purposes, confirmed_at, unsubscribed_at,
created_at, answer_by}}`, never the address, also in an app that calls
`JsonResource::withoutWrapping()`; `/manage/{token}/data` answers the copy itself,
and `/unsubscribe/{token}/position` its own shape, see
[Count and position](#count-and-position).

`answer_by` is null unless the address was [asked to confirm
again](../lifecycle.md#asking-confirmed-addresses-again) and has not answered;
then it is the deadline, and `status` already reads `confirmed`. A confirm page
that finds `answer_by` set on `GET /confirm/{token}` must post the token back,
not show "already confirmed": without that post the address leaves the list at
the deadline. Once answered, the same link answers `answer_by: null`.
Validation errors are always `422` JSON, also for a plain form post.

Nothing looks up data by address over HTTP: access and erasure by address exist
only as commands and PHP APIs. A person reaches their own data only through a
manage link mailed to their address, unless the project turns manage links off.

## Count and position

Two figures for a waitlist page, both off until a list turns them on in its
project's definition, and only on a list with double opt-in:

```php
$project->list('beta', purpose: 'waitlist')
    ->publicCount()      // GET /waitlist/count?list=beta
    ->publicPosition();  // GET /waitlist/unsubscribe/{token}/position
```

**The count** is the number of addresses on the list that confirmed and have not
left, the `confirmed` status, rounded down to a multiple of 10. Below 10 it is
`null`, so a small list does not show each signup, and a frontend shows its own
wording instead of a number. It is cached for 60 seconds in your application's
default cache store, so a page polling it does not reach the database on every
request and does not see a signup the moment it lands:

```json
{"data": {"list": "beta", "count": 1230}}
```

It is a request like the wording: the project resolver picks the project, the
project's [origins](../securing-the-endpoints.md#websites-that-may-use-a-project)
and the `useWaitlist` gate (`WaitlistAction::ViewCount`) decide, and it is in the
`signup` group with its limiter and middleware. Pending addresses are not
counted: anyone can type someone else's address into a form.

**The position** is the person's place among the confirmed addresses of their
list, in the order their current cycles were confirmed: `1` for the earliest; within
the same second, the cycle that started first comes first. People
who left are not counted, so a position moves up when someone ahead leaves, and
someone who leaves and comes back joins at the end. A position is cached for a
minute in the default cache store, so it can lag behind someone ahead leaving by
that long; leaving or coming back shows at once. It is `null` while the person
has not confirmed, or after they left:

```json
{"data": {"project": "default", "list": "beta", "status": "confirmed", "position": 42}}
```

Both stay off on a list without double opt-in, whatever its definition says: an
address typed into the form counts at once there, so a figure that moves with it
would tell whether that address was on the list already. Such a list answers
`count_disabled` and `position_disabled`.

It is read only with the unsubscribe token the person got in every mail, never
by address, so it cannot be used to look anyone up, and it is in the `links`
group, limited per token. The entry's own project and list apply. Neither figure
names anyone, but a position is exact: whoever just confirmed learns how many
are on the list, and someone who checks again learns that people ahead of them
left. Someone who leaves and signs up again with their own address can read the
exact size whenever they like, so on a list that publishes positions the
rounding of the count hides nothing. Turn it on only for a list where that is
fine.

PHP callers get both without the switches, through
`Waitlist::for('beta')->publicCount()` and `->position($entry)`, see the
[PHP API](php-api.md#one-list-scopedwaitlist).

## Errors

A refusal the package answers itself names it in `error`, next to the
`message`:

| Status | `error` | When |
| --- | --- | --- |
| `404` | `invalid_token` | The token belongs to no entry. |
| `404` | `unknown_list` | `GET /purposes` or `GET /count` for a list the project does not have. |
| `404` | `count_disabled` | `GET /count` for a list that does not publish its count. |
| `404` | `position_disabled` | `GET /unsubscribe/{token}/position` for an entry whose list does not publish positions. |
| `409` | `not_subscribed` | `PUT /manage/{token}/purposes` once the address has left. |
| `404` | `manage_links_disabled` | `POST /manage-link` or `POST /unsubscribe/{token}/manage-link` for a project without manage links; by address, the same for every address. |
| `409` | `list_unavailable` | `PUT /manage/{token}/purposes` for a list removed since. |
| `410` | `expired_token` | The confirm or manage link has expired. |
| `422` | `spam_check_failed` | `POST /waitlist` or `POST /manage-link` by address, when the [spam check](../securing-the-endpoints.md#bot-protection-via-spamprotector) refuses; the `message` is `Spam check failed.` and there are no `errors`. |

A `404` without `error` did not come from the package: there is no such route,
often a URL without the routes' prefix, the routes are switched off, or the
`useWaitlist` gate keeps the caller from the project. Validation errors are
`422` with `errors`, as Laravel answers them, and rate limits `429` with
`Retry-After`.

## What a client does with a status

Statuses besides the package's own `error` codes, as a client sees them:

| Status | Usually | Retry? | Log |
| --- | --- | --- | --- |
| `401` | The caller sent no credentials the API accepts: `authentication.required` is on, or the project resolver needs them. A wrong token looks the same. | No, fix the token | Yes, as a setup error |
| `403` | The `useWaitlist` gate refused the caller: a project that is not its own, or wording from a guest; or the project lists origins and the browser's website is not among them. | No | Yes |
| `404` without `error` | Not the package: a URL without the routes' prefix, routes switched off, a group of routes left out for a setting that did not read, or the gate keeping the caller from the project. | No | Yes, as a setup error |
| `419` | A CSRF or form check of your own on the signup group, such as `web`. | No, send what the check wants | Yes |
| `422` | Validation (`errors`), or `spam_check_failed` | No, fix the input | No, show it to the person |
| `429` | A rate limit. `Retry-After` says when. | After the wait | Count it |
| `500` | A setting that does not read, or an error in your app. The log names the key. | Only after a fix | Yes |
| no answer | A timeout, a refused connection or a dead network. | Yes, with a limit | Yes, with the reason |

A signup or a confirmation that fails with a `500` for a setting stored
nothing, so a retry after the fix is safe. The [JS client](https://github.com/Taldres/laravel-waitlist-js#results)
turns each of these into a result and logs the reason without the token or any
personal data.

## Routes that are off

While `waitlist.routes.enabled` is off the package registers no route, so every
URL under the prefix is Laravel's plain `404`. A route cache built while they
were on still holds them: run `php artisan route:clear`, see
[When the routes are registered](../securing-the-endpoints.md#when-the-routes-are-registered).

A route setting that does not read is reported to your logs when the routes are
registered, naming the config key, and leaves out the routes it belongs to: those
requests are a plain `404`, not a `500`. That includes an empty
`WAITLIST_ROUTES_ENABLED=`, which is no decision to turn the routes off. See
[When the routes are registered](../securing-the-endpoints.md#when-the-routes-are-registered).

## GET never changes anything

Neither does HEAD, and no response to a link from a mail carries the address:
mail scanners and link previews follow every link they find. So the links in
mails point at your pages, and the page posts the token back. A GET on a package
route, from an old link or a mail client opening the `List-Unsubscribe` header,
only reports state and redirects to your page.

## Where mail links point

Set your pages per project with `urls()` in its definition; `{token}` is replaced, and a link for one
purpose gets `?purpose=`, ahead of a `#fragment` if your page keeps the token out
of server logs that way:

```php
Waitlist::define(function (ProjectDefinition $project): void {
    // purposes and lists ...

    $project->urls(
        confirm: 'https://app.example.com/waitlist/confirm/{token}',
        unsubscribe: 'https://app.example.com/waitlist/leave/{token}',
        manage: 'https://app.example.com/waitlist/preferences/{token}',
        confirmed: 'https://app.example.com/waitlist/thanks',
        expired: 'https://app.example.com/waitlist/expired',
        invalid: 'https://app.example.com/waitlist/oops',
        unsubscribed: 'https://app.example.com/waitlist/goodbye',
        erased: 'https://app.example.com/waitlist/erased',
    );
});
```

- **`confirm`, `unsubscribe`, `manage`** are where mail links point, and where a
  GET on a package route redirects. An invalid or expired token goes to `invalid`
  or `expired` instead.
- **The others** are where a browser lands after posting a form to the package.
  Unset ones answer JSON, and requests sending `Accept: application/json` always
  get JSON. Calling the endpoints server to server? Set that header explicitly.
- **Unset mail links** point at the package routes when they are enabled, and
  are `null` when they are not: set one before the first signup, or the
  confirmation mail has no link.
- **An unknown token** belongs to no project, so it uses the default project's
  pages.

Links to the package routes themselves are built from `APP_URL`, never from the
Host header of the request that triggered the mail, so a forged signup cannot
send a token to another host. Without an absolute `http` or `https` `APP_URL`
there is no such link: building one throws `InvalidConfigurationException`, and
the signup is undone, rather than falling back to the request's host.

Need more logic than a pattern, a landing page per list or locale for example?
Bind your own `LinkGenerator`, see
[the SPA example](../examples/landing-page-spa.md#2-point-the-mail-links-at-your-pages).

## Metadata

The signup accepts only the fields the resolved project and the posted list
define, with their validation rules; any other key is a `422`, and so is any
metadata for a list without fields. Values are stored encrypted:

```php
$project->fields(['source' => ['nullable', 'string', 'max:50']]);           // every list of the project
$project->list('teams', purpose: 'waitlist')->fields(fn () => [              // this list, on top
    'company' => ['required', 'string', 'max:120'],
]);
```

```json
{"email": "jane@example.com", "list": "teams", "purposes": {"waitlist": "2026-10"}, "metadata": {"source": "footer", "company": "Initech"}}
```

A refused value names its field, as `metadata.company`; an unknown key names
`metadata` and lists the keys the list accepts. When the list does not exist,
the `422` names `list`. See [Fields](../projects.md#fields).

## Projects and who may call

The signup, the wording and a manage link requested by address act for the
project the bound `ProjectResolver` picks. The default always picks `default`;
with several projects, bind another, see
[Projects, lists and fields](../projects.md#over-http-tell-the-package-which-project-a-signup-is-for).
With `PublishableKeyResolver`, a client sends the project's publishable key in
`X-Waitlist-Key`; a missing or unknown key answers `403`. The key is public and
authorizes nothing.
Token links need none, since every token belongs to an entry.

Then the `useWaitlist` gate decides whether the caller may act for that project
and list, before the body is validated. Its default lets everyone through and
keeps a caller acting for a project to its own (`404` otherwise); with
`waitlist.authentication.required`, guests get `401` and callers without a
project `403`. A signup that brings wording also needs `RegisterWording`, which
by default only a caller acting for the project gets; others get `403`. A gate
of your own answers with whatever status it returns. See
[Who may call](../projects.md#who-may-call-the-usewaitlist-gate).

## Rate limits, CORS, bots

Each route group has its own named limiter: `signup` (signup, wording, count, manage
links by address; `waitlist`, per IP, or per server for one calling for a project) and
`links` (everything with a token; `waitlist-links`, per token, so one-click
unsubscribes from a mail provider's servers get through). CORS for a frontend on another origin, trusted proxies and
bot protection are covered in [Securing the endpoints](../securing-the-endpoints.md).
