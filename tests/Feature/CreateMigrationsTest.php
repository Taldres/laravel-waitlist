<?php

declare(strict_types=1);

dataset('create migrations', fn (): array => array_map(
    basename(...),
    glob(__DIR__.'/../../database/migrations/*_create_*.php') ?: [],
));

it('skips a table it finds, as a copy published a second time does', function (string $file) {
    $migration = include __DIR__.'/../../database/migrations/'.$file;

    expect(fn () => $migration->up())->not->toThrow(Throwable::class);
})->with('create migrations');
