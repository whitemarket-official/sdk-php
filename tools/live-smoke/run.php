<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke; // the namespace lets this script use the @internal HeaderValue

// Live smoke (SDK_SPEC 6.4, `make live-smoke`): read-only calls against the real API with the token from
// WHITEMARKET_PARTNER_TOKEN or, like the examples, the git-ignored dotenv file in the repository root. Writes
// var/live-smoke/report.md. Exit: 0 all passed, 1 a step failed, 2 no usable token.

use WhiteMarket\Sdk\Auth\HeaderValue;

use function WhiteMarket\Sdk\Examples\partnerTokenOrNull;

require __DIR__ . '/../../vendor/autoload.php';

$token = partnerTokenOrNull();
if ($token === null || !HeaderValue::isValid($token)) {
    fwrite(\STDERR, "live-smoke: WHITEMARKET_PARTNER_TOKEN is not set or not a valid header value.\n");
    exit(2);
}

$results = Smoke::run($token);

$dir = __DIR__ . '/../../var/live-smoke';
if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
    fwrite(\STDERR, "live-smoke: cannot create var/live-smoke.\n");
    exit(2);
}
file_put_contents($dir . '/report.md', Report::render($results));

echo 'live-smoke: ', Report::summary($results), "\n";
foreach ($results as $r) {
    if ($r->status === StepStatus::Fail) {
        echo '  FAIL ', $r->name, ': ', $r->detail, "\n";
    }
}
exit(Report::exitCode($results));
