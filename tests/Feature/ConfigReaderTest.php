<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Taldres\Waitlist\Config\ConfigReader;
use Taldres\Waitlist\Config\PackageConfig;
use Taldres\Waitlist\Contracts\EmailNormalizer;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Models\WaitlistConsent;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\DefaultEmailNormalizer;

/**
 * A reader over a config that holds only $value, at the place of $key.
 */
function configReaderOf(ConfigKey $key, mixed $value): ConfigReader
{
    $config = [];
    Arr::set($config, substr($key->value, strlen('waitlist.')), $value);

    return ConfigReader::of($config);
}

/**
 * The reader over config/waitlist.php as it reads when env() holds $text for
 * $variable, the way a line of .env arrives.
 */
function configReaderFromEnv(string $variable, string $text): ConfigReader
{
    $repository = Env::getRepository();
    $repository->set($variable, $text);

    try {
        return ConfigReader::of(PackageConfig::merge([]));
    } finally {
        $repository->clear($variable);
    }
}

/**
 * A text that must never show up in a message.
 */
function configReaderSecret(): string
{
    return 'secret-value-that-must-not-leak-4242';
}

describe('a number', function () {
    it('reads a whole number as written, leading zeros included', function (string $text, int $expected) {
        expect(configReaderOf(ConfigKey::ManageTokenTtl, $text)->integer(ConfigKey::ManageTokenTtl, min: 0))->toBe($expected)
            ->and(configReaderOf(ConfigKey::ManageRequestCooldown, $text)->integerOrNull(ConfigKey::ManageRequestCooldown, min: 0))->toBe($expected);
    })->with([
        'plain' => ['5', 5],
        'with a leading zero' => ['05', 5],
        'with several leading zeros' => ['0007', 7],
        'zero' => ['0', 0],
        'only zeros' => ['000', 0],
        'with a plus sign and leading zeros' => ['+05', 5],
        'minus zero' => ['-0', 0],
        'minus zeros' => ['-00', 0],
        'with whitespace around it' => [" \t05\n", 5],
        'the largest int' => [(string) PHP_INT_MAX, PHP_INT_MAX],
        'the largest int with leading zeros' => ['00'.PHP_INT_MAX, PHP_INT_MAX],
    ]);

    it('is refused when it is not a whole number or does not fit an int', function (string $text) {
        expect(fn () => configReaderOf(ConfigKey::ManageTokenTtl, $text)->integer(ConfigKey::ManageTokenTtl, min: 0))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.manage.token_ttl config must be a whole number, got ')
            ->and(fn () => configReaderOf(ConfigKey::ManageRequestCooldown, $text)->integerOrNull(ConfigKey::ManageRequestCooldown, min: 0))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.manage.request_cooldown config must be a whole number or null, got ');
    })->with([
        'a fraction' => '2.5',
        'a fraction with a zero' => '05.0',
        'an exponent' => '1e3',
        'hexadecimal' => '0x1A',
        'octal' => '0o17',
        'a unicode digit' => "\u{0663}",
        'a number past the largest int' => '9223372036854775808',
        'a number past the largest int with a leading zero' => '09223372036854775808',
        'a number before the smallest int' => '-9223372036854775809',
        'binary' => '0b11',
        'an underscore' => '1_000',
        'a thousands comma' => '1,000',
        'two numbers' => '5 5',
        'two lines' => "5\n6",
        'a no-break space' => "\u{00A0}5",
        'a form feed' => "\f5",
        'a sign alone' => '-',
        'two signs' => '--5',
        'both signs' => '+-5',
        'text' => 'five',
        'an empty text' => '',
        'whitespace' => ' ',
    ]);

    it('is refused when it is neither an int nor a text, naming the type', function (mixed $value, string $got) {
        expect(fn () => configReaderOf(ConfigKey::ManageTokenTtl, $value)->integer(ConfigKey::ManageTokenTtl, min: 0))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.manage.token_ttl config must be a whole number, got {$got}.")
            ->and(fn () => configReaderOf(ConfigKey::ManageRequestCooldown, $value)->integerOrNull(ConfigKey::ManageRequestCooldown, min: 0))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.manage.request_cooldown config must be a whole number or null, got {$got}.");
    })->with([
        'true' => [true, 'bool'],
        'false' => [false, 'bool'],
        'a float without a fraction' => [5.0, 'float'],
        'a list' => [[], 'array'],
        'an object' => [new stdClass, 'stdClass'],
    ]);

    it('keeps the range of the setting, for a number written with a sign or leading zeros as well', function (string $text) {
        expect(fn () => configReaderOf(ConfigKey::MaxConfirmations, $text)->integerOrNull(ConfigKey::MaxConfirmations, min: 1))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.double_opt_in.max_confirmations config must be at least 1.');
    })->with(['00', '-0', '-05', '-1', (string) PHP_INT_MIN]);
});

describe('a switch', function () {
    it('leaves whitespace around it alone', function () {
        expect(configReaderOf(ConfigKey::StoreIp, " \ton\n\r\x0B")->boolean(ConfigKey::StoreIp))->toBeTrue();
    });

    it('is refused when it is anything else, never cast, naming the type', function (mixed $value, string $got) {
        expect(fn () => configReaderOf(ConfigKey::StoreIp, $value)->boolean(ConfigKey::StoreIp))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.privacy.store_ip config must be true or false, got {$got}.");
    })->with([
        'a decimal text' => ['1.0', 'a text it does not read'],
        'a float one' => [1.0, 'float'],
        'a float zero' => [0.0, 'float'],
        'a leading zero' => ['01', 'a text it does not read'],
        'the text null' => ['null', 'a text it does not read'],
        'the text (true)' => ['(true)', 'a text it does not read'],
        'the text 2' => ['2', 'a text it does not read'],
        'the number -1' => [-1, 'int'],
        'two words' => ['yes please', 'a text it does not read'],
        'a list holding true' => [[true], 'array'],
        'an object' => [new stdClass, 'stdClass'],
        'a closure' => [fn () => true, 'Closure'],
        'an object that prints as true' => [new class
        {
            public function __toString(): string
            {
                return 'true';
            }
        }, 'class@anonymous'],
    ]);
});

describe('an environment variable', function () {
    it('reads as a switch in the forms the docs name, whatever env() makes of them', function (string $text, bool $expected) {
        expect(configReaderFromEnv('WAITLIST_STORE_IP', $text)->boolean(ConfigKey::StoreIp))->toBe($expected);
    })->with([
        'true' => ['true', true],
        '(true)' => ['(true)', true],
        'false' => ['false', false],
        '(false)' => ['(false)', false],
        'on' => ['on', true],
        'Off' => ['Off', false],
        'yes' => ['yes', true],
        'no' => ['no', false],
        '1' => ['1', true],
        '0' => ['0', false],
        'yes between spaces' => [' yes ', true],
        'on in quotes' => ['"on"', true],
    ]);

    it('is refused as a switch when env() makes it null or empty, or it is something else', function (string $text, string $got) {
        expect(fn () => configReaderFromEnv('WAITLIST_STORE_IP', $text)->boolean(ConfigKey::StoreIp))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.privacy.store_ip config must be true or false, got {$got}");
    })->with([
        'null' => ['null', 'null'],
        '(null)' => ['(null)', 'null'],
        'empty' => ['empty', 'an empty text'],
        'nothing' => ['', 'an empty text'],
        'maybe' => ['maybe', 'a text it does not read'],
        'a number past 1' => ['2', 'a text it does not read'],
        'blank' => [' ', 'a text it does not read'],
    ]);

    it('reads as a number, around whitespace and with a leading zero', function (string $text, int $expected) {
        expect(configReaderFromEnv('WAITLIST_RATE_LIMIT_SIGNUP', $text)->integer(ConfigKey::SignupPerMinute, min: 1))->toBe($expected);
    })->with([
        'a number' => ['25', 25],
        'between spaces' => [' 25 ', 25],
        'in quotes' => ['"25"', 25],
        'a leading zero' => ['05', 5],
    ]);

    it('is refused as a number when it is not one, or null where null is not allowed', function (string $text, string $message) {
        expect(fn () => configReaderFromEnv('WAITLIST_RATE_LIMIT_SIGNUP', $text)->integer(ConfigKey::SignupPerMinute, min: 1))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.routes.rate_limits.signup_per_minute config {$message}");
    })->with([
        'zero' => ['0', 'must be at least 1.'],
        'null' => ['null', 'must be a whole number, got null.'],
        '(null)' => ['(null)', 'must be a whole number, got null.'],
        'empty' => ['empty', 'must be a whole number, got an empty text'],
        'nothing' => ['', 'must be a whole number, got an empty text'],
        'an exponent' => ['1e3', 'must be a whole number, got a text it does not read.'],
        'a fraction' => ['10.5', 'must be a whole number, got a text it does not read.'],
        'words' => ['ten', 'must be a whole number, got a text it does not read.'],
        'true, which env() makes a bool' => ['true', 'must be a whole number, got bool.'],
    ]);

    it('reads as a number or null, with null and zero both switching a cooldown off', function (string $text, ?int $expected) {
        expect(configReaderFromEnv('WAITLIST_RESEND_COOLDOWN', $text)->integerOrNull(ConfigKey::ResendCooldown, min: 0))->toBe($expected);
    })->with([
        'a number' => ['10', 10],
        'zero' => ['0', 0],
        'null' => ['null', null],
        '(null)' => ['(null)', null],
    ]);

    it('is refused as a number or null when empty, so a blank line cannot switch a cooldown off', function (string $text, string $got) {
        expect(fn () => configReaderFromEnv('WAITLIST_RESEND_COOLDOWN', $text)->integerOrNull(ConfigKey::ResendCooldown, min: 0))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.double_opt_in.resend_cooldown config must be a whole number or null, got {$got}");
    })->with([
        'empty' => ['empty', 'an empty text'],
        '(empty)' => ['(empty)', 'an empty text'],
        'nothing' => ['', 'an empty text'],
        'blank' => [' ', 'a text it does not read'],
        'false, which env() makes a bool' => ['false', 'bool'],
    ]);

    it('reads as a name that may be unset, where null and empty both leave it unset', function (string $text, ?string $expected) {
        expect(configReaderFromEnv('WAITLIST_CONNECTION', $text)->stringOrNull(ConfigKey::Connection))->toBe($expected);
    })->with([
        'a name' => ['mysql', 'mysql'],
        'a number-like name' => ['5', '5'],
        'null' => ['null', null],
        'empty' => ['empty', null],
        'nothing' => ['', null],
    ]);

    it('is refused as a name when env() turns it into a bool', function (string $text) {
        expect(fn () => configReaderFromEnv('WAITLIST_CLIENT_IP_HEADER', $text)->stringOrNull(ConfigKey::ClientIpHeader))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.authentication.client_ip_header config must be a text or null, got bool.');
    })->with(['true', 'false', '(true)', '(false)']);

    it('reads as a schedule, where null and empty leave it off, and refuses what is no cron expression', function () {
        expect(configReaderFromEnv('WAITLIST_RETENTION_SCHEDULE', '0 4 * * *')->cronOrNull(ConfigKey::RetentionSchedule))->toBe('0 4 * * *')
            ->and(configReaderFromEnv('WAITLIST_RETENTION_SCHEDULE', 'null')->cronOrNull(ConfigKey::RetentionSchedule))->toBeNull()
            ->and(configReaderFromEnv('WAITLIST_RETENTION_SCHEDULE', '')->cronOrNull(ConfigKey::RetentionSchedule))->toBeNull()
            ->and(fn () => configReaderFromEnv('WAITLIST_RETENTION_SCHEDULE', 'nightly')->cronOrNull(ConfigKey::RetentionSchedule))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.retention.schedule config must be a cron expression such as 15 3 * * *, or null, got one that is not.');
    });
});

describe('a name', function () {
    it('may be empty only where the caller says so', function () {
        $reader = configReaderOf(ConfigKey::RoutesPrefix, '');

        expect($reader->string(ConfigKey::RoutesPrefix, allowEmpty: true))->toBe('')
            ->and(fn () => $reader->string(ConfigKey::RoutesPrefix))->toThrow(InvalidConfigurationException::class, 'got an empty text')
            ->and($reader->stringOrNull(ConfigKey::RoutesPrefix))->toBeNull();
    });

    it('is refused when it is not a text, never cast to one, naming the type', function (mixed $value, string $got) {
        expect(fn () => configReaderOf(ConfigKey::Connection, $value)->stringOrNull(ConfigKey::Connection))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.connection config must be a text or null, got {$got}.");
    })->with([
        'zero' => [0, 'int'],
        'false' => [false, 'bool'],
        'true' => [true, 'bool'],
        'a list' => [[], 'array'],
    ]);

    it('keeps the whitespace it has around other characters, as a name is looked up as written', function () {
        expect(configReaderOf(ConfigKey::DefaultList, ' beta ')->string(ConfigKey::DefaultList))->toBe(' beta ')
            ->and(configReaderOf(ConfigKey::Connection, ' side ')->stringOrNull(ConfigKey::Connection))->toBe(' side ');
    });
});

describe('a middleware or guard name', function () {
    it('is refused when it is blank or no list of names', function (mixed $value) {
        expect(fn () => configReaderOf(ConfigKey::RoutesMiddleware, $value)->middleware(ConfigKey::RoutesMiddleware))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.routes.middleware config must be a list of middleware names, got ');
    })->with([
        'a blank name' => [['api', ' ']],
        'an empty name' => [['api', '']],
        'an empty text' => [''],
        'a blank text' => ['  '],
        'null among names' => [[null]],
        'a nested list' => [[['web']]],
        'a map' => [['csrf' => 'web']],
        'keys that do not start at zero' => [[1 => 'web']],
        'a gap in the keys' => [[0 => 'web', 2 => 'api']],
        'null' => [null],
        'an int' => [5],
        'a bool' => [true],
        'an object' => [new stdClass],
    ]);

    it('reads a list of names with parameters and a single text as they are', function () {
        expect(configReaderOf(ConfigKey::RoutesMiddleware, ['api', 'throttle:60,1', 'can:useWaitlist'])->middleware(ConfigKey::RoutesMiddleware))->toBe(['api', 'throttle:60,1', 'can:useWaitlist'])
            ->and(configReaderOf(ConfigKey::RoutesMiddleware, 'api')->middleware(ConfigKey::RoutesMiddleware))->toBe(['api']);
    });

    it('reads guard names and null as they are', function () {
        expect(configReaderOf(ConfigKey::AuthenticationGuards, ['sanctum', null, 'web'])->guards(ConfigKey::AuthenticationGuards))->toBe(['sanctum', null, 'web'])
            ->and(configReaderOf(ConfigKey::AuthenticationGuards, [3 => 'web', 7 => null])->guards(ConfigKey::AuthenticationGuards))->toBe(['web', null]);
    });

    it('refuses what is neither a name nor null', function (mixed $value) {
        expect(fn () => configReaderOf(ConfigKey::AuthenticationGuards, $value)->guards(ConfigKey::AuthenticationGuards))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.authentication.guards config must list guard names, or null for the default guard, got ');
    })->with([
        'a blank name' => [[' ']],
        'an empty name' => [['']],
        'a bool' => [[false]],
        'a nested list' => [[[null]]],
        'an object' => [[new stdClass]],
        'one name instead of a list' => ['web'],
        'null instead of a list' => [null],
        'an int' => [5],
    ]);
});

describe('an export column list', function () {
    it('reads one column, and the order it is given in', function () {
        expect(configReaderOf(ConfigKey::ExportColumns, ['email'])->columns(ConfigKey::ExportColumns, ['id', 'email']))->toBe(['email'])
            ->and(configReaderOf(ConfigKey::ExportColumns, ['email', 'id'])->columns(ConfigKey::ExportColumns, ['id', 'email']))->toBe(['email', 'id']);
    });

    it('matches the allowed columns exactly', function (string $column) {
        expect(fn () => configReaderOf(ConfigKey::ExportColumns, [$column])->columns(ConfigKey::ExportColumns, ['id', 'email']))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.export.columns config lists columns that may not be exported: {$column}.");
    })->with(['EMAIL', ' email']);

    it('is refused when it is no list of names', function (mixed $value) {
        expect(fn () => configReaderOf(ConfigKey::ExportColumns, $value)->columns(ConfigKey::ExportColumns, ['id', 'email']))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.export.columns config must be a list of column names, got ');
    })->with([
        'keys that do not start at zero' => [['1' => 'email']],
        'a blank name' => [[' ']],
        'one name instead of a list' => ['email'],
        'null' => [null],
    ]);

    it('is refused with the columns that may not be exported, naming each', function () {
        expect(fn () => configReaderOf(ConfigKey::ExportColumns, ['email', 'secret', 'id', 'token'])->columns(ConfigKey::ExportColumns, ['id', 'email']))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.export.columns config lists columns that may not be exported: secret, token.');
    });
});

describe('a schedule', function () {
    it('is refused when it parses but can never run, as the prune it stands for would silently never happen', function (string $cron) {
        expect(fn () => configReaderOf(ConfigKey::RetentionSchedule, $cron)->cronOrNull(ConfigKey::RetentionSchedule))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.retention.schedule config must be a cron expression that can run, such as 15 3 * * *, or null, got one that never does.');
    })->with([
        'the 30th of February' => '0 0 30 2 *',
        'the 31st of February' => '0 0 31 2 *',
        'the 31st of April' => '0 0 31 4 *',
        'the 31st of June, September and November' => '0 0 31 6,9,11 *',
        'the 30th and 31st of February' => '15 3 30-31 2 *',
    ]);

    it('still reads the leap day, the last day of a long month and a day reached through the weekday', function (string $cron) {
        expect(configReaderOf(ConfigKey::RetentionSchedule, $cron)->cronOrNull(ConfigKey::RetentionSchedule))->toBe($cron);
    })->with([
        'the leap day' => '0 0 29 2 *',
        'the last days of February' => '0 0 28-31 2 *',
        'the 31st of a long month' => '0 0 31 1 *',
        'the 31st of every month with one' => '0 0 31 * *',
        'the 31st of January and April' => '0 0 31 1,4 *',
        'the 30th of February or a Monday' => '0 0 30 2 1',
        'the default' => '15 3 * * *',
        'a macro' => '@daily',
    ]);

    it('is still told apart from one that does not parse', function (string $cron) {
        expect(fn () => configReaderOf(ConfigKey::RetentionSchedule, $cron)->cronOrNull(ConfigKey::RetentionSchedule))
            ->toThrow(InvalidConfigurationException::class, 'must be a cron expression such as 15 3 * * *, or null, got one that is not.');
    })->with([
        'words' => 'not-a-cron',
        'blank' => ' ',
        'a minute of 60' => '60 * * * *',
        'a weekday of 8' => '0 0 * * 8',
        'a step of zero' => '*/0 * * * *',
        'six fields with seconds' => '* * * * * *',
        'a reboot shortcut' => '@reboot',
        'an unknown shortcut' => '@every_minute',
    ]);

    it('is refused when it is no text, before the parser is asked', function (mixed $cron, string $got) {
        expect(fn () => configReaderOf(ConfigKey::RetentionSchedule, $cron)->cronOrNull(ConfigKey::RetentionSchedule))
            ->toThrow(InvalidConfigurationException::class, "The waitlist.retention.schedule config must be a text or null, got {$got}.");
    })->with([
        'a list' => [['15 3 * * *'], 'array'],
        'a number' => [3, 'int'],
    ]);

    it('is refused with a null byte, or read as unset when empty', function () {
        expect(fn () => configReaderOf(ConfigKey::RetentionSchedule, "15 3 * * *\0")->cronOrNull(ConfigKey::RetentionSchedule))
            ->toThrow(InvalidConfigurationException::class, ConfigKey::RetentionSchedule->value)
            ->and(configReaderOf(ConfigKey::RetentionSchedule, '')->cronOrNull(ConfigKey::RetentionSchedule))->toBeNull()
            ->and(configReaderOf(ConfigKey::RetentionSchedule, null)->cronOrNull(ConfigKey::RetentionSchedule))->toBeNull();
    });
});

interface ConfigReaderAppNormalizer extends EmailNormalizer {}

abstract class ConfigReaderAbstractNormalizer implements EmailNormalizer {}

describe('an implementation', function () {
    it('is refused when it is the contract itself, which the container would resolve to itself again', function () {
        expect(fn () => configReaderOf(ConfigKey::EmailNormalizer, EmailNormalizer::class)->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.email_normalizer config must name a class that implements '.EmailNormalizer::class.', not the contract itself.');
    });

    it('may be another interface or an abstract class, which an app can bind in the container', function (string $class) {
        expect(configReaderOf(ConfigKey::EmailNormalizer, $class)->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class))->toBe($class);
    })->with([ConfigReaderAppNormalizer::class, ConfigReaderAbstractNormalizer::class]);

    it('is still refused when it does not implement the contract', function () {
        expect(fn () => configReaderOf(ConfigKey::EmailNormalizer, stdClass::class)->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class))
            ->toThrow(InvalidConfigurationException::class, 'must point to a '.EmailNormalizer::class.' implementation.');
    });
});

describe('a class name', function () {
    it('is refused unless it is a text naming a subclass or an implementation, an instance included', function (mixed $value) {
        expect(fn () => configReaderOf(ConfigKey::ConsentModel, $value)->subclass(ConfigKey::ConsentModel, WaitlistConsent::class))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.consent_model config must point to a '.WaitlistConsent::class.' subclass.')
            ->and(fn () => configReaderOf(ConfigKey::EmailNormalizer, $value)->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.email_normalizer config must point to a '.EmailNormalizer::class.' implementation.');
    })->with([
        'a class that does not exist' => ['Nope\\Nothing'],
        'an empty text' => [''],
        'null' => [null],
        'an int' => [5],
        'a list holding the class' => [[WaitlistConsent::class, DefaultEmailNormalizer::class]],
        'an instance of the model' => [new WaitlistConsent],
        'an instance of the implementation' => [new DefaultEmailNormalizer],
    ]);

    it('is refused for a sibling of the base', function () {
        expect(fn () => configReaderOf(ConfigKey::ConsentModel, WaitlistEntry::class)->subclass(ConfigKey::ConsentModel, WaitlistConsent::class))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.consent_model config must point to a '.WaitlistConsent::class.' subclass.');
    });
});

describe('a route prefix', function () {
    it('is refused with a placeholder, as the links in mails cannot be built for it', function (string $prefix) {
        expect(fn () => configReaderOf(ConfigKey::RoutesPrefix, $prefix)->routePrefix(ConfigKey::RoutesPrefix))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.routes.prefix config must be a path without placeholders, such as waitlist or api/waitlist, got one with braces.');
    })->with([
        'a placeholder in the middle' => 'p/{project}/waitlist',
        'a placeholder alone' => '{project}',
        'an optional placeholder' => 'waitlist/{locale?}',
        'an opening brace' => 'wait{list',
        'a closing brace' => 'wait}list',
    ]);

    it('reads a plain path, and the root', function (string $prefix) {
        expect(configReaderOf(ConfigKey::RoutesPrefix, $prefix)->routePrefix(ConfigKey::RoutesPrefix))->toBe($prefix);
    })->with(['waitlist', 'api/v1/waitlist', 'a-b_c', '']);

    it('is refused when it is no text', function (mixed $prefix) {
        expect(fn () => configReaderOf(ConfigKey::RoutesPrefix, $prefix)->routePrefix(ConfigKey::RoutesPrefix))
            ->toThrow(InvalidConfigurationException::class, ConfigKey::RoutesPrefix->value);
    })->with([[['waitlist']], [5], [true], [null], [new stdClass]]);
});

describe('a config', function () {
    it('that is no array is refused, at the root and in a group', function () {
        expect(fn () => ConfigReader::of('off'))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist config must be an array of settings, got string.')
            ->and(fn () => ConfigReader::of(['routes' => 'api'])->routePrefix(ConfigKey::RoutesPrefix))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.routes config must be an array of settings, got string.');
    });

    it('that is an object holding settings is refused as well, though it could be read', function (object $object) {
        expect(fn () => ConfigReader::of($object))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist config must be an array of settings, got '.$object::class.'.')
            ->and(fn () => ConfigReader::of(['routes' => ['rate_limits' => $object]])->integer(ConfigKey::SignupPerMinute, min: 1))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.routes.rate_limits config must be an array of settings, got '.$object::class.'.');
    })->with([
        'a collection' => fn () => new Collection(['signup_per_minute' => 10]),
        'an array object' => fn () => new ArrayObject(['signup_per_minute' => 10]),
    ]);

    it('reports a key, a group and a list of values the same way when they are missing', function (array $config) {
        expect(fn () => ConfigReader::of($config)->integer(ConfigKey::SignupPerMinute, min: 1))
            ->toThrow(InvalidConfigurationException::class, 'The waitlist.routes.rate_limits.signup_per_minute config is missing. If the config is cached, cache it again with php artisan config:cache.');
    })->with([
        'no config at all' => [[]],
        'no routes' => [['privacy' => []]],
        'empty rate limits' => [['routes' => ['rate_limits' => []]]],
        'the sibling key only' => [['routes' => ['rate_limits' => ['link_per_minute' => 10]]]],
        'a list of limits' => [['routes' => ['rate_limits' => [10, 10, 600, 120]]]],
    ]);
});

describe('a message', function () {
    it('never carries the value it refuses, as a config value may be a secret', function (Closure $read) {
        try {
            $read();
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->not->toContain(configReaderSecret());

            return;
        }

        $this->fail('Nothing was thrown.');
    })->with([
        'a switch' => [fn () => configReaderOf(ConfigKey::StoreIp, configReaderSecret())->boolean(ConfigKey::StoreIp)],
        'a number' => [fn () => configReaderOf(ConfigKey::ManageTokenTtl, configReaderSecret())->integer(ConfigKey::ManageTokenTtl, min: 1)],
        'a number or null' => [fn () => configReaderOf(ConfigKey::ResendCooldown, configReaderSecret())->integerOrNull(ConfigKey::ResendCooldown, min: 0)],
        'a number below its minimum' => [fn () => configReaderOf(ConfigKey::ManageTokenTtl, '-4242424242424242')->integer(ConfigKey::ManageTokenTtl, min: 1)],
        'a text' => [fn () => configReaderOf(ConfigKey::DefaultList, [configReaderSecret()])->string(ConfigKey::DefaultList)],
        'a text or null' => [fn () => configReaderOf(ConfigKey::Connection, [configReaderSecret()])->stringOrNull(ConfigKey::Connection)],
        'a schedule' => [fn () => configReaderOf(ConfigKey::RetentionSchedule, configReaderSecret())->cronOrNull(ConfigKey::RetentionSchedule)],
        'middleware' => [fn () => configReaderOf(ConfigKey::RoutesMiddleware, [configReaderSecret(), 1])->middleware(ConfigKey::RoutesMiddleware)],
        'guards' => [fn () => configReaderOf(ConfigKey::AuthenticationGuards, [configReaderSecret(), 1])->guards(ConfigKey::AuthenticationGuards)],
        'columns' => [fn () => configReaderOf(ConfigKey::ExportColumns, [configReaderSecret(), 1])->columns(ConfigKey::ExportColumns, ['id'])],
        'a model' => [fn () => configReaderOf(ConfigKey::Model, configReaderSecret())->subclass(ConfigKey::Model, WaitlistEntry::class)],
        'a binding' => [fn () => configReaderOf(ConfigKey::EmailNormalizer, configReaderSecret())->implementation(ConfigKey::EmailNormalizer, EmailNormalizer::class)],
        'a group' => [fn () => ConfigReader::of(['privacy' => configReaderSecret()])->boolean(ConfigKey::StoreIp)],
        'the whole config' => [fn () => ConfigReader::of(configReaderSecret())],
    ]);
});
