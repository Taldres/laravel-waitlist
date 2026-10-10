<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Facades\Waitlist;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Tests\Fixtures\ArrayProjectCatalog;
use Taldres\Waitlist\Tests\Fixtures\HeaderProjectResolver;

enum FieldsTestPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
}

final class FieldsTestUppercase implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strtoupper($value) !== $value) {
            $fail('The :attribute must be uppercase.');
        }
    }
}

/**
 * Laravel hands every DataAwareRule the request data, so one instance shared
 * across requests would validate with the previous request's data.
 */
final class FieldsTestDataAwareRule implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void {}
}

final class FieldsTestCountingResolver implements ProjectResolver
{
    public static int $calls = 0;

    public function resolve(Request $request): string
    {
        self::$calls++;

        return 'default';
    }
}

/**
 * A product form: a country from everyone, contact details only from
 * enterprises.
 */
function defineFieldsTestProductProject(): void
{
    Waitlist::define('acme', function (ProjectDefinition $project): void {
        $project->purpose('waitlist', ['2026-10' => 'Email me when Acme launches.']);

        $project->fields([
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'company' => ['nullable', 'string', 'max:120'],
        ]);

        $project->list('individuals', purpose: 'waitlist');
        $project->list('startups', purpose: 'waitlist');
        $project->list('enterprises', purpose: 'waitlist')->fields([
            'company' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);
    });
}

beforeEach(function () {
    // The provider registers routes at boot, before this flag is set, so register them again.
    // Middleware is cleared: the testbench skeleton defines no "api" group.
    config()->set(ConfigKey::RoutesEnabled->value, true);
    config()->set(ConfigKey::RoutesMiddleware->value, []);

    require __DIR__.'/../../routes/waitlist.php';
});

describe('a project without fields', function () {
    it('refuses every metadata key', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['source' => 'footer']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata' => 'The metadata field is prohibited.']);
    });

    it('still signs up without metadata', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertStatus(202);
    });
});

describe('project fields', function () {
    beforeEach(fn () => defineDefaultProject(fn (ProjectDefinition $project) => $project->fields([
        'source' => ['nullable', 'string', 'max:50'],
    ])));

    it('accepts them on every list and stores them with the entry', function (string $list) {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => $list, 'purposes' => waitlistConsent(), 'metadata' => ['source' => 'footer']])
            ->assertStatus(202);

        expect(Waitlist::for($list)->find('user@example.com')->metadata)->toBe(['source' => 'footer']);
    })->with(['beta', 'launch']);

    it('refuses any other key and names the ones it accepts', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['source' => 'footer', 'ref' => 'x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata' => 'The metadata field must be an array with only these keys: source.']);

        expect(WaitlistEntry::query()->count())->toBe(0);
    });

    it('applies their rules', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['source' => str_repeat('x', 51)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.source');
    });

    it('refuses metadata that is not an object', function () {
        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => 'footer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata');
    });

    it('uses the default list\'s fields when the request names no list', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->list('default', purpose: 'waitlist')->fields(['ref' => ['required', 'string']]));

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.ref');

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'purposes' => waitlistConsent(), 'metadata' => ['ref' => 'footer']])
            ->assertStatus(202);
    });
});

describe('fields per project and list', function () {
    beforeEach(function () {
        config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

        defineFieldsTestProductProject();

        $this->signUp = fn (string $list, array $metadata, string $project = 'acme') => $this->withHeader('X-Waitlist-Project', $project)->postJson('/waitlist', [
            'email' => 'user@example.com',
            'list' => $list,
            'purposes' => ['waitlist' => '2026-10'],
            'metadata' => $metadata,
        ]);
    });

    it('requires a project field on every list of the project', function (string $list) {
        ($this->signUp)($list, ['company' => 'Initech'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata.country' => 'field is required.']);

        ($this->signUp)($list, ['country' => 'Germany', 'company' => 'Initech'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.country');
    })->with(['individuals', 'startups', 'enterprises']);

    it('takes a list\'s own fields on top of the project\'s', function () {
        ($this->signUp)('enterprises', ['country' => 'DE', 'company' => 'Initech', 'contact_name' => 'Jane', 'contact_phone' => '+1 555 0100'])
            ->assertStatus(202);

        expect(Waitlist::project('acme')->for('enterprises')->find('user@example.com')->metadata)->toBe([
            'country' => 'DE',
            'company' => 'Initech',
            'contact_name' => 'Jane',
            'contact_phone' => '+1 555 0100',
        ]);
    });

    it('keeps a list\'s fields off the other lists of the project', function (string $list) {
        ($this->signUp)($list, ['country' => 'DE', 'contact_phone' => '+1 555 0100'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata' => 'The metadata field must be an array with only these keys: country, company.']);

        expect(WaitlistEntry::query()->count())->toBe(0);
    })->with(['individuals', 'startups']);

    it('lets a list replace a project field of the same name', function () {
        ($this->signUp)('individuals', ['country' => 'DE'])->assertStatus(202);

        ($this->signUp)('enterprises', ['country' => 'DE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata.company' => 'field is required.']);
    });

    it('keeps one project\'s fields away from another project', function () {
        ($this->signUp)('beta', ['country' => 'DE'], 'default')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata' => 'The metadata field is prohibited.']);
    });

    it('names the list, not the fields, when the project has no such list', function () {
        ($this->signUp)('nope', ['country' => 'DE', 'anything' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['list' => 'The selected list is not available.'])
            ->assertJsonMissingValidationErrors('metadata');
    });
});

describe('rules', function () {
    it('takes rule objects, which a cached config could not hold', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(fn () => [
            'plan' => ['required', Rule::enum(FieldsTestPlan::class)],
            'source' => ['nullable', Rule::in(['footer', 'hero'])],
            'code' => ['nullable', new FieldsTestUppercase],
        ]));

        $signUp = fn (array $metadata) => $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => $metadata]);

        $signUp(['plan' => 'enterprise', 'source' => 'sidebar', 'code' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metadata.plan', 'metadata.source', 'metadata.code' => 'The metadata.code must be uppercase.']);

        $signUp(['plan' => 'pro', 'source' => 'hero', 'code' => 'ABC'])->assertStatus(202);
    });

    it('builds the rules anew for every request, so no rule object carries one request\'s data into the next', function () {
        $built = [];

        defineDefaultProject(function (ProjectDefinition $project) use (&$built): void {
            $project->fields(function () use (&$built): array {
                return ['source' => [$built[] = new FieldsTestDataAwareRule]];
            });
        });

        $this->postJson('/waitlist', ['email' => 'one@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['source' => 'a']])->assertStatus(202);
        $this->postJson('/waitlist', ['email' => 'two@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['source' => 'b']])->assertStatus(202);

        expect($built)->toHaveCount(2)
            ->and($built[0])->not->toBe($built[1])
            ->and($built[0]->data['email'])->toBe('one@example.com')
            ->and($built[1]->data['email'])->toBe('two@example.com');
    });

    it('lets a rule refer to another field', function () {
        defineDefaultProject(fn (ProjectDefinition $project) => $project->fields([
            'company' => ['nullable', 'string'],
            'contact_name' => ['nullable', 'string', 'required_with:metadata.company'],
        ]));

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent(), 'metadata' => ['company' => 'Initech']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata.contact_name');
    });
});

describe('the project comes first', function () {
    it('answers a refused request before validating it', function () {
        config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

        $this->postJson('/waitlist', ['metadata' => 'not even an object'])->assertStatus(401);
    });

    it('asks the resolver once per signup', function () {
        config()->set(ConfigKey::ProjectResolver->value, FieldsTestCountingResolver::class);
        FieldsTestCountingResolver::$calls = 0;

        $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'beta', 'purposes' => waitlistConsent()])->assertStatus(202);

        expect(FieldsTestCountingResolver::$calls)->toBe(1);
    });

    it('reads the fields of a catalog of your own', function () {
        config()->set(ConfigKey::Catalog->value, ArrayProjectCatalog::class);
        config()->set(ConfigKey::ProjectResolver->value, HeaderProjectResolver::class);

        $signUp = fn (array $metadata) => $this->withHeader('X-Waitlist-Project', 'shop')
            ->postJson('/waitlist', ['email' => 'user@example.com', 'list' => 'restock', 'purposes' => ['restock' => 'v1'], 'metadata' => $metadata]);

        $signUp([])->assertStatus(422)->assertJsonValidationErrors('metadata.sku');
        $signUp(['sku' => 'TSHIRT-M'])->assertStatus(202);
    });
});

it('accepts every list name a definition accepts', function (string $name) {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->list($name, purpose: 'waitlist'), lists: false);

    $this->postJson('/waitlist', ['email' => 'user@example.com', 'list' => $name, 'purposes' => waitlistConsent()])->assertStatus(202);
})->with(['beta', 'Beta-2', 'product.42', 'shop:restock', 'a_b', '7']);

describe('in your own code', function () {
    it('hands out the fields of a list, the list\'s own replacing the project\'s', function () {
        defineFieldsTestProductProject();

        expect(Waitlist::project('acme')->for('individuals')->fields())->toBe([
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'company' => ['nullable', 'string', 'max:120'],
        ])->and(Waitlist::project('acme')->for('enterprises')->fields())->toBe([
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'company' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);
    });

    it('hands out no fields when the project defines none', function () {
        expect(Waitlist::for('beta')->fields())->toBe([]);
    });

    it('refuses a list the project does not have', function () {
        defineFieldsTestProductProject();

        Waitlist::project('acme')->for('nope')->fields();
    })->throws(UnknownWaitlistException::class);

    it('leaves add() to trust its caller, as documented', function () {
        $entry = Waitlist::for('beta')->add('user@example.com', waitlistConsent(), ['anything' => 'goes'])->entry;

        expect($entry->metadata)->toBe(['anything' => 'goes']);
    });
});

it('lists the fields of every project and list in the processing record', function () {
    defineDefaultProject(fn (ProjectDefinition $project) => $project->fields(['source' => ['nullable', 'string']]));
    defineFieldsTestProductProject();

    Artisan::call('waitlist:privacy');

    expect(Artisan::output())
        ->toContain('| Metadata (default, any other list): source | waitlist_entries.metadata | Encrypted with APP_KEY |')
        ->toContain('| Metadata (acme, list individuals): country, company | waitlist_entries.metadata | Encrypted with APP_KEY |')
        ->toContain('| Metadata (acme, list enterprises): country, company, contact_name, contact_phone | waitlist_entries.metadata | Encrypted with APP_KEY |');

    Artisan::call('waitlist:privacy', ['--project' => 'acme']);

    expect(Artisan::output())
        ->not->toContain('Metadata (default')
        ->toContain('| Metadata (acme, list startups): country, company |');
});
