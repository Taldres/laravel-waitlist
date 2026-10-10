<?php

declare(strict_types=1);

use Taldres\Waitlist\Support\UnsubscribeToken;

it('puts the purpose into the query, ahead of a fragment', function (string $url, string $expected) {
    expect(UnsubscribeToken::withPurpose($url, 'news letter'))->toBe($expected);
})->with([
    'a plain URL' => ['https://app.test/leave/T', 'https://app.test/leave/T?purpose=news+letter'],
    'a URL with a query' => ['https://app.test/leave?t=T', 'https://app.test/leave?t=T&purpose=news+letter'],
    'the token in the fragment' => ['https://app.test/leave#T', 'https://app.test/leave?purpose=news+letter#T'],
    'a query and a fragment' => ['https://app.test/leave?x=1#T', 'https://app.test/leave?x=1&purpose=news+letter#T'],
    'an empty fragment' => ['https://app.test/leave#', 'https://app.test/leave?purpose=news+letter#'],
    'a fragment holding another #' => ['https://app.test/leave#a#b', 'https://app.test/leave?purpose=news+letter#a#b'],
    'a question mark only in the fragment' => ['https://app.test/leave#a?b', 'https://app.test/leave?purpose=news+letter#a?b'],
]);
