<?php

declare(strict_types=1);

use Taldres\Waitlist\Enums\ConfigKey;

/**
 * The files an app reads to learn the package: the docs, the README, the
 * bundled skills (which agents copy into apps) and the config's own comments.
 *
 * @return array<string, string> path => text
 */
function documentationFiles(): array
{
    $root = dirname(__DIR__, 2);
    $paths = [
        ...glob($root.'/docs/*.md'),
        ...glob($root.'/docs/*/*.md'),
        ...glob($root.'/resources/boost/skills/*/SKILL.md'),
        $root.'/README.md',
        $root.'/config/waitlist.php',
    ];

    $files = [];

    foreach ($paths as $path) {
        $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
    }

    return $files;
}

/**
 * The variables the config file reads.
 *
 * @return list<string>
 */
function configVariables(): array
{
    preg_match_all("/env\\('(WAITLIST_[A-Z0-9_]+)'/", (string) file_get_contents(dirname(__DIR__, 2).'/config/waitlist.php'), $matches);

    return array_values(array_unique($matches[1]));
}

describe('what the documentation names', function () {
    it('only names settings this version has', function () {
        $keys = array_map(fn (ConfigKey $key) => $key->value, ConfigKey::cases());
        $known = array_fill_keys($keys, true);

        foreach ($keys as $key) {
            $parts = explode('.', $key);

            for ($length = 1; $length <= count($parts); $length++) {
                $known[implode('.', array_slice($parts, 0, $length))] = true;
            }
        }

        // Names of routes in the docs' own examples, which are the app's, not the package's.
        $examples = ['waitlist.check', 'waitlist.confirmed', 'waitlist.expired', 'waitlist.signup'];
        $unknown = [];

        foreach (documentationFiles() as $path => $text) {
            preg_match_all('/(?<![\w\/.-])(waitlist\.[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*)/', $text, $matches);

            foreach ($matches[1] as $name) {
                if (! isset($known[$name]) && ! in_array($name, $examples, true)) {
                    $unknown[$path][] = $name;
                }
            }
        }

        expect($unknown)->toBe([], 'a setting that this version does not have is named in the documentation');
    });

    it('only names variables the config file reads, or the JavaScript client does', function () {
        $known = [...configVariables(), 'WAITLIST_API_URL', 'WAITLIST_API_TOKEN'];
        $unknown = [];

        foreach (documentationFiles() as $path => $text) {
            preg_match_all('/\bWAITLIST_[A-Z0-9_]+\b/', $text, $matches);

            foreach (array_unique($matches[0]) as $variable) {
                if (! in_array($variable, $known, true)) {
                    $unknown[$path][] = $variable;
                }
            }
        }

        expect($unknown)->toBe([], 'a variable that this version does not read is named in the documentation');
    });

    it('lists every variable of the config file in the configuration reference', function () {
        $reference = documentationFiles()['docs/reference/configuration.md'];

        foreach (configVariables() as $variable) {
            expect($reference)->toContain($variable);
        }
    });
});
