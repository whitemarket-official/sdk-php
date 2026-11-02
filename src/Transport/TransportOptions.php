<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Settings of the GraphQL HTTP transport (SDK_SPEC 1.3, ADR 0002 "Transport"). Timeouts are in seconds; they apply to
 * the default Guzzle client only, because PSR-18 has no timeout API.
 */
final readonly class TransportOptions
{
    public const DEFAULT_BASE_URL = 'https://api.white.market';

    public const DEFAULT_GRAPHQL_PATH = '/graphql/partner';

    public function __construct(
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public string $graphqlPath = self::DEFAULT_GRAPHQL_PATH,
        public float $connectTimeout = 10.0,
        public float $requestTimeout = 30.0,
        public int $maxResponseBytes = 32 * 1024 * 1024,
        public ?string $userAgentSuffix = null,
    ) {
        OptionRules::assertHttpsOrigin($baseUrl);
        OptionRules::assertPath('graphqlPath', $graphqlPath);
        if ($connectTimeout <= 0 || $requestTimeout <= 0) {
            throw new \InvalidArgumentException('Timeouts must be positive.');
        }
        if ($maxResponseBytes < 1) {
            throw new \InvalidArgumentException('maxResponseBytes must be at least 1.');
        }
        OptionRules::assertUserAgentSuffix($userAgentSuffix);
    }

    /**
     * These options with another API origin, validated like the constructor argument.
     */
    public function withBaseUrl(string $baseUrl): self
    {
        return new self(
            baseUrl: $baseUrl,
            graphqlPath: $this->graphqlPath,
            connectTimeout: $this->connectTimeout,
            requestTimeout: $this->requestTimeout,
            maxResponseBytes: $this->maxResponseBytes,
            userAgentSuffix: $this->userAgentSuffix,
        );
    }

    public function graphqlUrl(): string
    {
        return rtrim($this->baseUrl, '/') . $this->graphqlPath;
    }

    /**
     * `whitemarket-sdk-php/<version> (PHP/<version>)`, followed by the suffix when one is set.
     */
    public function userAgent(): string
    {
        return OptionRules::userAgent($this->userAgentSuffix);
    }
}
