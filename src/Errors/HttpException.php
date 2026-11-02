<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * The server answered with a non-2xx status (SDK_SPEC 1.5 `HttpError`); the concrete class tells which one.
 * `bodyExcerpt` holds the start of the response body for diagnostics, with secrets masked; it is not part of the
 * message.
 */
abstract class HttpException extends WhiteMarketException
{
    public const BODY_EXCERPT_BYTES = 512;

    public readonly string $bodyExcerpt;

    final public function __construct(
        public readonly int $status,
        #[\SensitiveParameter]
        string $body,
        public readonly ?int $retryAfter = null,
    ) {
        $this->bodyExcerpt = BodyRedactor::excerpt($body, self::BODY_EXCERPT_BYTES);
        parent::__construct(\sprintf('HTTP %d from the WhiteMarket API', $status));
    }

    /**
     * The exception class for a non-2xx status (SDK_SPEC 1.5). `retryAfter` is in seconds, kept for 429 and 503.
     *
     * @internal called by the transport
     */
    public static function fromStatus(int $status, #[\SensitiveParameter] string $body, ?int $retryAfter = null): self
    {
        return match (true) {
            $status === 400 => new BadRequestException($status, $body),
            $status === 401 => new AuthenticationException($status, $body),
            $status === 429 => new RateLimitException($status, $body, $retryAfter),
            $status === 503 => new ServiceUnavailableException($status, $body, $retryAfter),
            $status >= 500 && $status <= 599 => new GeneralServerException($status, $body),
            default => new GeneralHttpException($status, $body),
        };
    }
}
