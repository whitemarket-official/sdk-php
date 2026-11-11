<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Deals;

use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarkerDealHistorySearchInput;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Live;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Deal;
use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\OfferCancelReason;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Paginator;
use WhiteMarket\Sdk\Models\Uuid;
use WhiteMarket\Sdk\Models\WireEncoder;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\Sleeper;
use WhiteMarket\Sdk\Transport\SystemClock;
use WhiteMarket\Sdk\Transport\SystemSleeper;

/**
 * `$client->deals` (SDK_SPEC 3.4): look deals up, list and search them, wait for a status, and the seller and buyer
 * actions on a deal.
 *
 * Reads are queries, which the transport retries on transient failures (SDK_SPEC 1.4). The three actions
 * (`requestCancel`, `reportOfferSent`, `cancelSendingOffer`) are mutations: each is sent exactly once and never
 * retried (CLAUDE.md rule 2). A mutation that takes an id or a customId is never sent twice to "try the other key":
 * a reference that could be either is resolved with queries first, see {@see reportOfferSent()}.
 *
 * A reference `$idOrCustomId` is the deal id (a UUID) or the `customId` of the purchase. The two cannot be told apart
 * by shape, because a customId is often a UUID too (the SDK generates UUIDv4 customIds). A UUID-shaped reference is
 * therefore looked up as an id first and then as a customId; any other string can only be a customId.
 *
 * @phpstan-import-type DealSource from ModelMapper
 */
final readonly class DealsApi
{
    /**
     * Most ids `deal_buyer_statuses` takes in one request (SDK_SPEC 3.4, WHITEMARKET_API.md 4).
     */
    public const MAX_STATUS_IDS = 300;

    /**
     * Longest comment of `deal_cancel_sending_offer` (SDK_SPEC 3.4, WHITEMARKET_API.md 4). The allowed characters are
     * ASCII, so the length in bytes is the length in characters.
     */
    public const MAX_COMMENT_LENGTH = 255;

    private const COMMENT_PATTERN = '/^[a-zA-Z0-9 _-]*$/D';

    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
        private Clock $clock = new SystemClock(),
        private Sleeper $sleeper = new SystemSleeper(),
        private WireEncoder $wire = new WireEncoder(),
    ) {}

    /**
     * One deal by id or customId: in progress (`deal_item`), else archived (`deal_history_item`). {@see Deal::$archivedAt}
     * tells which one it was.
     *
     * @throws \InvalidArgumentException `$idOrCustomId` is empty
     * @throws WhiteMarketException {@see NotFoundException} when neither a live nor an archived deal has this id or
     *                              customId; any other failure is thrown at once, without trying the next lookup
     */
    public function get(string $idOrCustomId): Deal
    {
        $this->assertReference($idOrCustomId);
        if (Uuid::isValid($idOrCustomId)) {
            try {
                return $this->lookup(id: $idOrCustomId, customId: null);
            } catch (NotFoundException) {
                // Not an id: a UUID-shaped customId is next.
            }
        }

        return $this->lookup(id: null, customId: $idOrCustomId);
    }

    /**
     * The status snapshot of many deals (`deal_buyer_statuses`), one request per {@see MAX_STATUS_IDS} ids. Each entry
     * of `$ids` is a deal id or a customId (the server accepts both); a state is matched to the entry that equals its
     * `id` or its `customId`.
     *
     * The result is keyed by the requested string, in request order, with each requested string once (an exact
     * comparison: two spellings that differ in case are two entries and both are sent, the server decides). A string in
     * UUID form matches a state's `id` without regard to case (RFC 9562); a customId is a free-form string and matches
     * exactly only. An entry the server does not know is absent from the result. PHP turns a key that is a decimal
     * integer string, such as the customId `"42"`, into an int key.
     *
     * @param list<string> $ids deal ids or customIds
     *
     * @return array<array-key, DealState>
     *
     * @throws WhiteMarketException
     */
    public function statuses(array $ids): array
    {
        $requested = array_values(array_unique($ids));
        $byId = [];
        $byFoldedId = [];
        $byCustomId = [];
        foreach (array_chunk($requested, self::MAX_STATUS_IDS) as $chunk) {
            foreach ($this->api->dealBuyerStatuses($chunk) as $i => $result) {
                $state = $this->mapper->dealState($result, \sprintf('deal_buyer_statuses[%d]', $i));
                $byId[$state->id] = $state;
                if (Uuid::isValid($state->id)) {
                    $byFoldedId[strtolower($state->id)] = $state;
                }
                if ($state->customId !== null) {
                    $byCustomId[$state->customId] = $state;
                }
            }
        }

        $found = [];
        foreach ($requested as $key) {
            $byKey = Uuid::isValid($key) ? ($byFoldedId[strtolower($key)] ?? null) : ($byId[$key] ?? null);
            $state = $byKey ?? $byCustomId[$key] ?? null;
            if ($state instanceof DealState) {
                $found[$key] = $state;
            }
        }

        return $found;
    }

    /**
     * Deals in progress where the caller is the buyer (`deal_buyer_list`).
     *
     * @param list<DealStatus>|null $statusesWith only these statuses
     * @param list<DealStatus>|null $statusesWithout all but these statuses
     *
     * @return list<Deal>
     *
     * @throws \InvalidArgumentException a status the API schema does not accept
     * @throws WhiteMarketException
     */
    public function listAsBuyer(?array $statusesWith = null, ?array $statusesWithout = null): array
    {
        return $this->deals($this->api->dealBuyerList($this->statusFilter($statusesWith), $this->statusFilter($statusesWithout)), 'deal_buyer_list');
    }

    /**
     * Deals in progress where the caller is the seller (`deal_seller_list`).
     *
     * @param list<DealStatus>|null $statusesWith only these statuses
     * @param list<DealStatus>|null $statusesWithout all but these statuses
     *
     * @return list<Deal>
     *
     * @throws \InvalidArgumentException a status the API schema does not accept
     * @throws WhiteMarketException
     */
    public function listAsSeller(?array $statusesWith = null, ?array $statusesWithout = null): array
    {
        return $this->deals($this->api->dealSellerList($this->statusFilter($statusesWith), $this->statusFilter($statusesWithout)), 'deal_seller_list');
    }

    /**
     * One page of archived deals (`deal_history`). Pass `new PageRequest(after: $previous->endCursor)` for the next page
     * while `$previous->hasNextPage` is true, or use {@see iterateHistory()}.
     *
     * @param DealHistoryFilter|MarkerDealHistorySearchInput|null $filter a raw `MarkerDealHistorySearchInput` is sent as is
     * @param ?PageRequest $page page size and cursor; the server's defaults when null
     *
     * @return Page<Deal>
     *
     * @throws WhiteMarketException
     */
    public function history(DealHistoryFilter|MarkerDealHistorySearchInput|null $filter = null, ?PageRequest $page = null): Page
    {
        $result = $this->api->dealHistory($page?->toInput(), $filter instanceof DealHistoryFilter ? $filter->toInput() : $filter);

        return $this->mapper->archivedDealPage($result, 'deal_history');
    }

    /**
     * Every archived deal that matches, page after page; a page is requested only when the previous one is consumed.
     * Keys are 0, 1, 2, ... across pages.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, Deal>
     *
     * @throws WhiteMarketException
     */
    public function iterateHistory(DealHistoryFilter|MarkerDealHistorySearchInput|null $filter = null, ?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->history($filter, new PageRequest($pageSize, $after)), 'deal_history');
    }

    /**
     * Asks to cancel a deal as the buyer (`deal_request_cancel`). Allowed only for the buyer and only after the seller
     * has not sent the offer for 30 minutes: check {@see Deal::$isCancelByBuyerSupported} and
     * {@see Deal::$cancelByBuyerNeedWait} first. A mutation: sent once, never retried.
     *
     * @param string $id the deal id (a UUID); a customId is not accepted here
     *
     * @throws \InvalidArgumentException `$id` is not a UUID; nothing was sent
     * @throws WhiteMarketException
     */
    public function requestCancel(string $id): Deal
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('The id must be a deal id (UUID); a customId is not accepted by deal_request_cancel.');
        }

        return $this->mapper->deal($this->api->dealRequestCancel($id), 'deal_request_cancel');
    }

    /**
     * Reports the Steam trade offer the seller sent (`deal_steam_offer_sent`; seller, MANUAL delivery). A mutation:
     * sent exactly once and never retried.
     *
     * A reference that is not UUID-shaped is sent as the customId. A UUID-shaped reference could be the id or a UUID
     * customId, and the mutation must not be sent twice to find out, so it is resolved with {@see get()} first (queries)
     * and the mutation is sent once with the id found.
     *
     * @param string $offerId the Steam trade offer id
     *
     * @throws \InvalidArgumentException `$idOrCustomId` is empty
     * @throws WhiteMarketException {@see NotFoundException} from the resolution means nothing was sent
     */
    public function reportOfferSent(string $idOrCustomId, string $offerId): Deal
    {
        [$id, $customId] = $this->mutationTarget($idOrCustomId);

        return $this->mapper->deal($this->api->dealSteamOfferSent(offerId: $offerId, customId: $customId, id: $id), 'deal_steam_offer_sent');
    }

    /**
     * The seller cancels sending the Steam offer (`deal_cancel_sending_offer`; seller, MANUAL delivery) and gets the
     * archived deal. Give the true reason: a wrong one can lead to sanctions (WHITEMARKET_API.md 7). A mutation: sent
     * exactly once and never retried; the reference is resolved as in {@see reportOfferSent()}.
     *
     * @param string $comment at most {@see MAX_COMMENT_LENGTH} characters of `a-z A-Z 0-9 space _ -`
     *
     * @throws \InvalidArgumentException `$idOrCustomId` is empty or `$comment` breaks the rule above; nothing was sent
     * @throws WhiteMarketException
     */
    public function cancelSendingOffer(string $idOrCustomId, OfferCancelReason $reason, string $comment): Deal
    {
        $this->assertCancelComment($comment);
        $wireReason = $this->wire->enum(Wire\MarketDealExternalCancelReason::class, $reason);
        [$id, $customId] = $this->mutationTarget($idOrCustomId);

        return $this->mapper->archivedDeal(
            $this->api->dealCancelSendingOffer(comment: $comment, reason: $wireReason, customId: $customId, id: $id),
            'deal_cancel_sending_offer',
        );
    }

    /**
     * The balance held in deals in one currency (`deal_balance_hold`).
     *
     * @throws WhiteMarketException
     */
    public function holdBalance(Currency $currency): Money
    {
        return $this->mapper->money($this->api->dealBalanceHold($this->wire->enum(Wire\Currency::class, $currency)), 'deal_balance_hold');
    }

    /**
     * The balances held in deals, one entry per currency (`deal_balances_hold`).
     *
     * @return list<Money>
     *
     * @throws WhiteMarketException
     */
    public function holdBalances(): array
    {
        return $this->mapper->list($this->api->dealBalancesHold(), 'deal_balances_hold', $this->mapper->money(...));
    }

    /**
     * Polls the status (`deal_buyer_statuses` through {@see statuses()}) until `$until` accepts it and returns that state.
     * The first poll is immediate; between polls the call sleeps `$pollInterval`, or less when the timeout ends sooner (then it polls once more at the deadline). Ready-made conditions: {@see Until}.
     *
     * Works for buyer and seller deals: `deal_buyer_statuses` answers for purchases only, so when it does not report the
     * deal the wait reads it with {@see get()} (`deal_item`, then the archive) and keeps doing so, one request per poll.
     * A deal the server does not report yet (a new deal may need a moment to become visible) counts as "not yet", and
     * so does a state `$until` rejects, including one with an unknown status. The wait does not end by itself when the
     * deal reaches a final status unless `$until` accepts it; every {@see Until} predicate does. Elapsed time is read
     * from the wall clock, so a clock change during the wait shifts the deadline.
     *
     * With `$live` the call does not sleep between polls: it waits in `$live->poll()` instead (so the live handlers
     * run meanwhile) and polls early when a deal event for the waited deal arrives. Any other event, product events
     * included, neither triggers a poll nor shortens the interval. An exception thrown by a live handler (or a
     * terminal live failure) propagates out of this call. The timeout semantics are the same as without `$live`.
     *
     * The timeout bounds the waiting between polls; a poll already in flight (its query retries per SDK_SPEC 1.4 and
     * the request timeout) may finish after it, so the call can return or throw up to one poll's duration after the
     * timeout.
     *
     * @param \Closure(DealState): bool $until the condition to wait for
     * @param ?float $timeout seconds to wait at most; null waits without limit. 0 polls exactly once
     * @param float $pollInterval seconds between polls
     * @param ?Live $live a live session (personal channel subscribed) whose deal events wake the wait early; the caller owns it (connect, subscribe, close)
     *
     * @throws \InvalidArgumentException `$idOrCustomId` is empty, `$pollInterval` is not a finite number above 0, or
     *                                   `$timeout` is neither null nor a finite number of at least 0
     * @throws WhiteMarketException a poll failed, or {@see DealWaitTimeoutException} when the timeout elapsed first (it
     *                              carries the last state seen)
     */
    public function waitFor(string $idOrCustomId, \Closure $until, ?float $timeout = null, float $pollInterval = 5.0, #[\SensitiveParameter] ?Live $live = null): DealState
    {
        $this->assertReference($idOrCustomId);
        if (!is_finite($pollInterval) || $pollInterval <= 0.0) {
            throw new \InvalidArgumentException('The poll interval must be a finite number of seconds above 0.');
        }
        if ($timeout !== null && (!is_finite($timeout) || $timeout < 0.0)) {
            throw new \InvalidArgumentException('The timeout must be null or a finite number of seconds, 0 or more.');
        }

        $start = $this->nowInSeconds();
        $viaGet = false;
        while (true) {
            $last = null;
            if (!$viaGet) {
                $last = array_values($this->statuses([$idOrCustomId]))[0] ?? null;
                $viaGet = !$last instanceof DealState;
            }
            if ($viaGet) {
                try {
                    $last = $this->mapper->dealStateOf($this->get($idOrCustomId));
                } catch (NotFoundException) {
                    // Not visible yet: keep polling.
                }
            }
            if ($last instanceof DealState && $until($last)) {
                return $last;
            }
            $sleep = $pollInterval;
            if ($timeout !== null) {
                $remaining = $timeout - ($this->nowInSeconds() - $start);
                if ($remaining <= 0.0) {
                    throw new DealWaitTimeoutException($idOrCustomId, $last, $timeout);
                }
                $sleep = min($pollInterval, $remaining);
            }
            if ($live instanceof Live) {
                $this->waitLive($live, $sleep, [$idOrCustomId, $last instanceof DealState ? $last->id : null]);
            } else {
                $this->sleeper->sleep($sleep);
            }
        }
    }

    /**
     * Waits up to `$seconds` in `$live->poll()`; returns early on a deal event of one of `$dealIds`.
     *
     * @param list<?string> $dealIds the waited reference and the deal id once known (ids compare case-insensitively)
     */
    private function waitLive(#[\SensitiveParameter] Live $live, float $seconds, array $dealIds): void
    {
        $end = $this->nowInSeconds() + $seconds;
        while (($left = $end - $this->nowInSeconds()) > 0.0) {
            $event = $live->poll(min($left, $seconds));
            if ($event instanceof DealEvent) {
                foreach ($dealIds as $id) {
                    if ($id !== null && strcasecmp($id, $event->dealId) === 0) {
                        return;
                    }
                }
            } elseif (!$event instanceof LiveEvent && $end - $this->nowInSeconds() > 0.0) {
                // A closed session returns at once: sleep the rest instead of spinning.
                $this->sleeper->sleep($end - $this->nowInSeconds());
            }
        }
    }

    /**
     * @param list<DealSource> $results
     *
     * @return list<Deal>
     *
     * @throws DecodeException
     */
    private function deals(array $results, string $path): array
    {
        return $this->mapper->list($results, $path, $this->mapper->deal(...));
    }

    /**
     * @param list<DealStatus>|null $statuses
     *
     * @return list<Wire\MarketDealStatus>|null
     *
     * @throws \InvalidArgumentException a status the API schema does not accept
     */
    private function statusFilter(?array $statuses): ?array
    {
        return $this->wire->enumList(Wire\MarketDealStatus::class, $statuses);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertCancelComment(string $comment): void
    {
        if (\strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The cancel comment is longer than %d characters.', self::MAX_COMMENT_LENGTH));
        }
        if (preg_match(self::COMMENT_PATTERN, $comment) !== 1) {
            throw new \InvalidArgumentException('The cancel comment may contain only the characters a-z, A-Z, 0-9, space, "_" and "-".');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertReference(string $idOrCustomId): void
    {
        if ($idOrCustomId === '') {
            throw new \InvalidArgumentException('The deal id or customId must not be empty.');
        }
    }

    /**
     * The key a mutation is sent with: the customId of a plain reference, the id found by {@see get()} for a UUID-shaped one.
     *
     * @return array{?string, ?string} `[id, customId]`, one of them null
     *
     * @throws \InvalidArgumentException
     * @throws WhiteMarketException {@see DecodeException} when the deal found is not the one referenced
     */
    private function mutationTarget(string $idOrCustomId): array
    {
        $this->assertReference($idOrCustomId);

        if (!Uuid::isValid($idOrCustomId)) {
            return [null, $idOrCustomId];
        }
        $deal = $this->get($idOrCustomId);
        if (strtolower($deal->id) !== strtolower($idOrCustomId) && $deal->customId !== $idOrCustomId) {
            throw new DecodeException('deal_item.id', 'the server returned a different deal than the one requested');
        }

        return [$deal->id, null];
    }

    /**
     * `deal_item`, then `deal_history_item` on not-found, under one key.
     *
     * @throws WhiteMarketException {@see NotFoundException} of the archive lookup when the deal is in neither
     */
    private function lookup(?string $id, ?string $customId): Deal
    {
        try {
            return $this->mapper->deal($this->api->dealItem(customId: $customId, id: $id), 'deal_item');
        } catch (NotFoundException) {
            // Not a deal in progress: the archive is next.
        }

        return $this->mapper->archivedDeal($this->api->dealHistoryItem(customId: $customId, id: $id), 'deal_history_item');
    }

    private function nowInSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
