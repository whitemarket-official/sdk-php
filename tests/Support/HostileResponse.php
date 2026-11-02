<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response of a faulty or hostile PSR-18 client: one of its methods, or one of the methods of its body stream,
 * throws `$failure` (an SDK exception such as `AuthenticationException` built from the client's own 401 included).
 */
final class HostileResponse extends Response
{
    public const POINTS = ['getStatusCode', 'getHeaderLine', 'hasHeader', 'getBody', 'read', 'eof', 'close'];

    /**
     * @param array<string, string> $headers
     */
    public static function at(string $point, \Throwable $failure, int $status = 200, string $body = '{"data":{"x":true}}', array $headers = []): ResponseInterface
    {
        \assert(\in_array($point, self::POINTS, true));

        return new self($point, $failure, $status, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function __construct(private readonly string $point, private readonly \Throwable $failure, int $status, array $headers, string $body)
    {
        parent::__construct($status, $headers, new HostileStream(Utils::streamFor($body), $point, $failure));
    }

    public function getStatusCode(): int
    {
        return $this->point === 'getStatusCode' ? throw $this->failure : parent::getStatusCode();
    }

    public function getHeaderLine($header): string
    {
        return $this->point === 'getHeaderLine' ? throw $this->failure : parent::getHeaderLine($header);
    }

    public function hasHeader($header): bool
    {
        return $this->point === 'hasHeader' ? throw $this->failure : parent::hasHeader($header);
    }

    public function getBody(): StreamInterface
    {
        return $this->point === 'getBody' ? throw $this->failure : parent::getBody();
    }
}
