# Laravel Waitlist documentation

New here? Start with [Getting started](getting-started.md): one waitlist with an
optional newsletter, end to end, over HTTP or in your own controllers.

## Which setup do you have?

| You have | Start with |
| --- | --- |
| One product, pages rendered by Laravel (Blade, Livewire, Inertia) | [Getting started](getting-started.md), the "in your own controllers" parts |
| One product, a static landing page or SPA | [Example: a landing page or SPA](examples/landing-page-spa.md) |
| Several products in one app | [Projects, lists and fields](projects.md), then [Example: several products](examples/several-products.md) |
| A central API serving the waitlists of all your products | [Example: a central waitlist API](examples/central-waitlist-api.md) |

## Guides

- [Projects, lists and fields](projects.md): what the default project is, and what changes with a second product
- [Purposes and wording](purposes-and-wording.md): what people agree to, versions, languages, registering wording from a CMS
- [Mail](mail.md): every mail the flow needs, sending services and newsletter tools, mail per project
- [Sync to your email provider](email-provider-sync.md): keep Brevo, Mailcoach and friends in step, both ways
- [Subscription lifecycle](lifecycle.md): cycles, tokens, what a withdrawal reaches, what fires when
- [Reporting](reporting.md): daily counts, the confirmed count over time, what survives an erasure
- [Securing the endpoints](securing-the-endpoints.md): architectures, CORS, rate limits, bot protection
- [Encryption and keys](encryption-and-keys.md): `APP_KEY`, rotation, your own encrypter
- [Extending](extending.md): your own models, contracts and macros
- [Upgrading](upgrading.md): the routine, how schema changes ship, what changed since the development branch

## Examples

- [A landing page or SPA](examples/landing-page-spa.md): signup form, confirmation and preference page in your own frontend
- [Several products in one app](examples/several-products.md): two products, two domains, one Laravel app
- [A central waitlist API](examples/central-waitlist-api.md): projects, lists and wording in your database
- [An invite flow](examples/invite-flow.md): let people in from the waitlist in batches

## Privacy

- [GDPR in practice](gdpr.md): what is stored, keys, retention, rights, a go-live checklist
- [Operator responsibilities and limitations](responsibility.md): what the package does not do for you

## Reference

- [Configuration](reference/configuration.md): every key and environment variable
- [PHP API](reference/php-api.md): the facade, per list, per project, across projects
- [HTTP API](reference/http-api.md): endpoints, responses, where mail links point
- [Events](reference/events.md): payloads, and which listener needs which
- [Commands](reference/commands.md): artisan commands and which projects they cover
