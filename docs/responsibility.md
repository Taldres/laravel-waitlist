# Operator responsibilities and limitations

`taldres/laravel-waitlist` provides technical features for a Laravel application.
The application operator is responsible for the lawfulness and security of its
processing and for assessing the rules applicable to its deployment. Installing
the package or enabling its defaults does not establish GDPR compliance.

## Software, documentation and license

The software and documentation are provided under the [MIT License](../LICENSE.md),
including its warranty and liability disclaimer. No legal advice, certification,
compliance warranty or determination of a lawful basis is provided. Examples,
defaults and the output of `waitlist:privacy` are technical guidance and do not
replace an assessment of the actual application.

These explanations do not extend the MIT License or override mandatory law,
data-subject rights or statutory duties. A statement that the operator bears
responsibility cannot exclude liability that applicable law does not permit to
be excluded. Obtain qualified legal review for your jurisdiction and any separate
hosting, support or commercial terms.

The package itself makes no outbound requests or built-in mail deliveries.
Installing it does not give the authors access to the application's waitlist data.
If data is separately shared for support, hosting or another service, assess that
processing separately. Controller and processor roles depend on the actual
purposes, decisions and activities, not on a label in this documentation.

## Decisions the operator must make

- Determine the purposes, lawful basis and applicable privacy and messaging rules.
- Provide the required notices and assess whether the actual consent flow is
  freely given, specific, informed and unambiguous. Display the registered text,
  offer separate optional choices and implement an accessible withdrawal flow.
- Select and justify retention periods. The defaults of 30 and 1095 days are not
  statutory periods or legal recommendations; `null` disables that time limit.
- Run and monitor scheduling and queues, protect encryption keys, configure HTTPS
  and access controls, and keep the application and its dependencies maintained.
- Assess recipients, processor agreements and international transfers. Configure
  and monitor mail delivery and propagation of withdrawal and erasure to providers.
- Handle rights requests, identity checks, deadlines, applicable exceptions,
  complaints and incidents across the whole processing, not just package tables.

## Technical boundaries

| Feature | Boundary the operator must account for |
| --- | --- |
| Consent records | Store the registered text, version, locale and lifecycle. They do not verify that a person saw the text, acted freely or gave legally valid consent. A matching hash only checks the supplied text identifier. |
| Wording sent by servers | With `wordingFromCallers()`, a project's servers register the text they send, and the consent stores it. The package checks who sent it and that a version never changes, not that the person saw that text; the server must send exactly what it showed and keep its credentials server-side. |
| Rate limits for servers | A server calling for a project is capped as a whole. Limiting each visitor stays with that server unless it forwards the visitor's address, which the package then trusts as given. |
| Double opt-in | Records requests and confirmations; mail is sent by the application. It does not by itself establish a lawful basis or permission for every message. |
| Encryption | Protects selected database fields. It does not encrypt every application response, log, queue payload, export or provider copy, and a party holding the key can read the values. |
| Metadata | The HTTP signup accepts only the fields a project or list defines. Server-side actions trust their callers; review what those callers store. |
| Request metadata | IP and user agent flags apply to activity rows. Web-server logs, framework rate-limit storage, monitoring and provider logs need separate assessment. |
| Retention | `waitlist:prune` handles pending entries, entries that left and activity IP/user agent. Active confirmed entries and the remaining reporting rows have no automatic expiry. |
| Erasure | Deletes the selected entry, its cycles and consents, and clears identifying fields in its activity. Reporting rows remain and are not guaranteed anonymous. Backups, queues, exports, logs and external copies require separate handling. |
| Access and export | Return package data in the requested scope and omit credentials. They do not produce every item of information required for a complete access response or include data held elsewhere. |
| Projects and self-service | Project keys are not tenant isolation. Self-service access and erasure cover one list entry; operator APIs can cover all projects where that scope is appropriate. |
| Events | Notify the application after commit. Dispatch does not prove that a listener, queued job or external provider completed an action; monitor failures and retries. |
| `waitlist:privacy` | Describes configured package storage and registered package-event listeners. It does not audit actual infrastructure, discover all recipients, include wildcard listeners or produce a complete processing record. |

Treat the residual activity log as potentially personal data. Decide whether it
may be retained, for how long and under which basis, or implement appropriate
deletion or aggregation in the application. Package model guards are application
controls, not database access controls or a guarantee of anonymity.

Protect pages containing personal data and credentials from caching and token
leakage. Configure `Cache-Control: no-store` where appropriate, prevent shared
caching of token-protected responses, redact tokens in logs and monitoring, and
control referrers and third-party resources on those pages. The package does not
configure your reverse proxy, CDN or frontend.

## Legal references

- [GDPR](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng): Articles 4, 5, 7,
  12–20, 24, 28, 32 and 82 address roles, processing duties and liability.
- Under German law, [BGB Section 276(3)](https://www.gesetze-im-internet.de/bgb/__276.html)
  prevents an advance exclusion of liability for intent;
  [BGB Section 309(7)](https://www.gesetze-im-internet.de/bgb/__309.html)
  places limits on certain exclusions in standard terms within its scope.

See [GDPR in practice](gdpr.md) for integration details and
[Reporting](reporting.md) for the fields that remain after erasure.
