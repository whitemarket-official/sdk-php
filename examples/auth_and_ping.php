<?php

declare(strict_types=1);

/*
 * Exchanges the Partner Token for an access token and checks the connection with `test_ping`.
 *
 * Run: php examples/auth_and_ping.php (WHITEMARKET_PARTNER_TOKEN in the environment or in .env). Read-only: no order,
 * purchase or listing is made.
 */

use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$client = WhiteMarketClient::create(partnerToken: partnerToken());

// The token is a secret: it is used, never printed. Secret prints as "***".
$token = $client->auth->accessToken();
echo 'Access token obtained: ', $token, "\n";

echo 'test_ping answered: ', $client->auth->ping(), "\n";
