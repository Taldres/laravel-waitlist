# Purposes and wording

A **purpose** is what someone agrees to: "email me when early access opens", "send
me the newsletter". A **list** is a waitlist a form signs people up for, and says
which purposes it asks for. The **wording** is the text of a purpose, in
versions, and the package stores a snapshot of it with every consent.

## Purposes and lists

```php
// app/Providers/WaitlistServiceProvider.php
Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', [
        '2026-10' => 'Email me when early access opens. I can unsubscribe at any time.',
    ]);
    $project->purpose('newsletter', [
        '2026-10' => 'Also send me the monthly product newsletter.',
    ]);

    $project->list('default', purpose: 'waitlist')->optional('newsletter');
    $project->list('beta', purpose: 'waitlist')->optional('newsletter');
    $project->list('news', purpose: 'newsletter');
});
```

The wording above is an example; replace it with your own.

- **One primary purpose per list**, which is required: sending the form agrees to
  it. Two purposes can never be bundled into one checkbox.
- **Optional purposes** are agreed to and withdrawn on their own; the form shows
  each as a separate, unticked checkbox.
- **Only defined lists accept signups.** A `'*'` list covers any list name, for
  lists you create on the fly.
- **Double opt-in per list.** On for every list (`WAITLIST_DOUBLE_OPT_IN`); a list
  can opt out with `->doubleOptIn(false)` and then starts confirmed.

Lists that share a primary purpose are separate waitlists, while an optional
purpose is one consent for the whole project. That decides what a withdrawal
reaches, see [Lifecycle](lifecycle.md#what-a-withdrawal-reaches).

With [several projects](projects.md#several-products-in-one-app), each project
defines its own purposes and lists; nothing is shared with another project.

## Versions

Never edit a version's wording: add a new version.

```php
$project->purpose('waitlist', [
    '2026-10' => 'Email me when early access opens.',
    '2026-11' => 'Email me when early access opens, and when my spot comes up.',
]);
```

The last version is current. Older ones stay accepted, so a form loaded before
the change still records what it showed. Remove a version to stop accepting it;
consents already given keep their snapshot. Removing a purpose altogether strands
nobody: a withdrawal is decided from the stored record.

## Languages

A version holds one text for every locale, or a text per locale:

```php
$project->purpose('waitlist', [
    '2026-11' => [
        'en' => 'Email me when early access opens.',
        'de' => 'Schreibt mir, wenn der Zugang startet.',
    ],
]);
```

`Waitlist::purposes('default', locale: 'de')` (or `GET /waitlist/purposes?locale=de`)
serves each purpose in the locale asked for, else the app's, else the fallback
locale, and says which one it served. The form posts that back with the version,
and the consent stores the locale next to the text:

```php
Waitlist::for('default')->add($email, ['waitlist' => ['version' => '2026-11', 'locale' => 'de']]);
```

A version with texts per locale needs the locale it was shown in; a single text
is taken for any locale. Post back the locale that was served, which may be a
fallback, not the one you asked for.

## Rendering the form

Render the wording from the package, never type it into the template:

```php
Waitlist::purposes('default');   // list<PurposeWording>: purpose, version, locale, text, hash(), required
```

The form posts back, per purpose the person chose, the version and locale it
showed. [Getting started](getting-started.md#3-show-the-wording) shows a Blade
form, [the SPA example](examples/landing-page-spa.md) a JavaScript one.

### Proving the form showed the registered text

A stored consent proves which wording was registered for a version, not what a
form displayed. A form that renders its own copy of the text, from a CMS for
example, posts back its hash, `hash('sha256', $text)`, and a mismatch is refused
instead of recorded:

```php
['waitlist' => ['version' => '2026-11', 'locale' => 'de', 'hash' => hash('sha256', $shownText)]]
```

`PurposeWording::hash()` and `GET /waitlist/purposes` serve the expected hash,
in lowercase hex; uppercase is accepted too.
`WAITLIST_REQUIRE_WORDING_HASH=true` refuses a consent that is newly recorded
without one, except one that brings its [text](#wording-sent-by-your-servers);
keeping or withdrawing a purpose needs none.

## Who owns the wording

Your project does: the package ships no legal texts. It resolves the version a
form posts back, rejects anything unknown, and stores a snapshot of the wording
with the consent. A visitor can choose a version but never send wording of their
own; only your project's own servers can, where you let them
([Wording sent by your servers](#wording-sent-by-your-servers)).

Definitions in code are the simple start. Lists, wording, fields and frontend
URLs come from a `ProjectCatalog`; the default one reads `Waitlist::define()`,
and you can bind your own to keep them in a database, see
[A central waitlist API](examples/central-waitlist-api.md).

## Registering wording where it is written

When the text lives in your frontend or CMS, register it from there instead of
copying it into the definition. `StoredWordingCatalog` reads the wording from the
`waitlist_wordings` table and everything else from `Waitlist::define()`; the
definition then names the lists and their purposes, and leaves out `purpose()`:

```php
// config/waitlist.php
'catalog' => Taldres\Waitlist\Support\StoredWordingCatalog::class,
```

Ship the wording with your frontend, purpose => version => text, and sync it on
deploy:

```json
{
  "waitlist": {
    "2026-10": "Email me when early access opens.",
    "2026-11": { "en": "Tell me when the beta opens.", "de": "Sagt mir, wenn die Beta startet." }
  }
}
```

```bash
php artisan waitlist:wording resources/wording.json [--project=acme] [--retire-missing]
```

- The same text again changes nothing, and a new locale for a registered version
  is added.
- Other text for a registered version is refused, so a consent keeps meaning what
  it said; nothing from a file with a conflict is written.
- `--retire-missing` retires the versions of the listed purposes that the file no
  longer has.

Switching from wording in the definition? `php artisan waitlist:wording --from-definitions`
registers what `purpose()` holds, older versions included, before you change
`waitlist.catalog`.

From code, for a CMS webhook for example:

```php
Waitlist::registerWording('waitlist', '2026-12', ['en' => '...', 'de' => '...']);  // texts added or restored
Waitlist::retireWording('waitlist', '2026-10');
Waitlist::project('acme')->registerWording(...);                                 // per project
```

Register automatically, on publish or deploy: by hand, the displayed and the
registered wording drift apart.

## Wording sent by your servers

When a site's server calls the API for its visitors (a central waitlist API,
Next.js Server Actions, see [architecture C](securing-the-endpoints.md#the-three-architectures)),
it can send the text along with the version, and the text lives in one place
only: the site that shows it. Let the project take it:

```php
Waitlist::define('acme', function (ProjectDefinition $project) {
    $project->wordingFromCallers();

    // Lists name their purposes as always; purpose() is optional now.
    $project->list('beta', purpose: 'waitlist')->optional('newsletter');
});
```

The server posts the version, the locale if it has several, and the exact text
it showed:

```json
{
  "email": "user@example.com",
  "list": "beta",
  "purposes": {
    "waitlist": { "version": "2026-10", "locale": "de", "text": "Ja, schreibt mir eine E-Mail, sobald Acme startet." }
  }
}
```

- **First use registers it.** A version the project does not have yet is
  stored in `waitlist_wordings`, with the server that sent it in
  `registered_by`, and `WordingRegistered` fires, worth a notification: consents
  will point at that text from now on. The project needs no wording before its
  first signup; until then `GET /waitlist/purposes` lists nothing for it, and
  `waitlist:privacy` says the wording is still to come.
- **A version never changes.** The same text again changes nothing. Other text
  for a known version is a `422`, and the signup is not stored: change the
  wording by sending a new version, which then is the current one. A new locale
  of a registered version is added. A version retired with `retireWording()`
  stays retired; a server sending it gets a `422`.
- **Versions in the definition win.** A version given with `purpose()` takes
  only its own text.
- **Only the project's servers.** Sending text asks the `useWaitlist` gate for
  `WaitlistAction::RegisterWording`, after `Subscribe`. By default only a caller
  acting for the project (`HasWaitlistProject`) gets it; a guest gets a `403`,
  whatever the project, and a project without `wordingFromCallers()` a `422`.
  The preference page never takes text: the person there chooses among the
  versions registered.
- **The version alone still works.** Once a version is registered, a signup
  may send only the version, or the version and the hash, like any form.

The API cannot see what a visitor's screen showed, with or without this: a
hash only proves that two copies match. Here your server's word is the proof,
so it must send exactly the text the person saw, and keep its token server-side.
A leaked token could register misleading wording just as it could sign people
up; the server it came from is on record with every version.

From PHP, `add()` takes the same choices for such a project, with the caller of
the `RequestContext` recorded, if any:

```php
Waitlist::project('acme')->for('beta')->add($email, [
    'waitlist' => ['version' => '2026-10', 'text' => $shownText],
]);
```
