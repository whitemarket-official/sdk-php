<?php

declare(strict_types=1);

/*
 * Uses the generated layer directly: a typed operation (`marketList`) and a hand-written document (`execute`).
 *
 * Run: php examples/raw_generated_operation.php (WHITEMARKET_PARTNER_TOKEN in the environment). Read-only: both
 * documents are queries. A document that is not clearly a query is treated as a mutation and is never retried.
 *
 * `$client->generated` runs on the client's own token, retry policy and error mapping.
 */

use WhiteMarket\Sdk\Generated\Input\ForwardPaginationInput;
use WhiteMarket\Sdk\WhiteMarketClient;

use function WhiteMarket\Sdk\Examples\partnerToken;

require __DIR__ . '/bootstrap.php';

$client = WhiteMarketClient::create(partnerToken: partnerToken());

$generated = $client->generated;

// 1. A typed operation: the schema's field name in camelCase, typed input, typed result.
$list = $generated->marketList(new ForwardPaginationInput(first: 3));
foreach ($list->edges ?? [] as $edge) {
    echo $edge->node->id ?? 'no node', "\n";
}

// 2. A raw document: the variables are JSON-ready values; the answer is the raw `data` array.
$data = $generated->execute('query Ping { test_ping }');
echo 'test_ping: ', json_encode($data['test_ping'] ?? null), "\n";
