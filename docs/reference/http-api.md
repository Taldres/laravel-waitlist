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
| GET | `/waitlist/purposes?list=&locale=` | Current wording per purpose, for the signup form, with the locale it is in |
| POST | `/waitlist` | Subscribe with `email`, `purposes` (`{purpose: version}` or `{purpose: {version, locale, hash}}`; a project's own servers may add `text`, see [Wording sent by your servers](../purposes-and-wording.md#wording-sent-by-your-servers)), optional `list` (`waitlist.default_list` otherwise) and `metadata` (only the [fields](#metadata) the project and list define). `202` with an identical body for new and known addresses; `422` names the field (`email`, `list`, `purposes`, `metadata`, `metadata.{field}`), always as JSON, or says `Spam check failed.`; the project resolver and the [`useWaitlist` gate](#projects-and-who-may-call) may answer first, e.g. `401`, `403` or `404`. |
| GET | `/waitlist/confirm/{token}` | Reports the token's state. `404` invalid, `410` expired. |
| POST | `/waitlist/confirm/{token}` | Confirm. |
| GET | `/waitlist/unsubscribe/{token}` | Reports the entry. `404` invalid. |
| POST | `/waitlist/unsubscribe/{token}[?purpose=]` | Unsubscribe, or withdraw one purpose; including [RFC 8058](https://www.rfc-editor.org/rfc/rfc8058) one-click. |
| POST | `/waitlist/manage-link` | Mail a manage link, with `token` (the unsubscribe token) or `email` and optional `list`. `202` with an identical body whether or not anything was sent. With `email`, the project resolver and the `useWaitlist` gate decide first, then the spam check applies. |
| GET | `/waitlist/manage/{token}` | Status and purposes in force, for the preference page. `404` invalid, `410` expired. |
| PUT | `/waitlist/manage/{token}/purposes` | Set the purposes; the primary one must be included, else `422`. `409` once the address has left. |
| POST | `/waitlist/manage/{token}/unsubscribe` | Leave this list, keeping the consent records for the configured retention period. |
| POST | `/waitlist/manage/{token}/data` | A JSON copy of everything stored for this entry, i.e. this list. |
| POST | `/waitlist/manage/{token}/erase` | Erase this entry, with `{"confirm": true}`. |

`/manage/…` takes the manage token, `/unsubscribe/…` and `/manage-link` the
unsubscribe token, `/confirm/…` the confirm token. The token routes answer
`{"data": {project, list, status, purposes, confirmed_at, unsubscribed_at,
created_at}}`, never the address, also in an app that calls
`JsonResource::withoutWrapping()`; `/manage/{token}/data` answers the copy itself.
Validation errors are always `422` JSON, also for a plain form post.

Nothing looks up data by address over HTTP: access and erasure by address exist
only as commands and PHP APIs. A person reaches their own data only through a
manage link mailed to their address.

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
send a token to another host.

Need more logic than a pattern, a landing page per list or locale for example?
Bind your own `ConfirmationUrlGenerator`, see
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
project a `ProjectResolver` picks (`waitlist.project_resolver`). The default
always picks `default`; with several projects, bind your own, see
[Projects, lists and fields](../projects.md#over-http-tell-the-package-which-project-a-signup-is-for).
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

Each route group has its own named limiter: `signup` (signup, wording, manage
links; `waitlist`, per IP, or per server for one calling for a project) and
`links` (everything with a token; `waitlist-links`, per token, so one-click
unsubscribes from a mail provider's servers get through). CORS for a frontend on another origin, trusted proxies and
bot protection are covered in [Securing the endpoints](../securing-the-endpoints.md).
