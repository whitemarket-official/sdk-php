<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Live\LiveOptions;

/**
 * The only class that references Guzzle (ADR 0002): builds the default PSR-18 clients, one for queries, one for
 * mutations that opens a connection per request and never rewinds the body, and one for export downloads, and the
 * default PSR-17 message factory.
 *
 * All three are Guzzle with the SDK timeouts (ADR 0002 "Default client: Guzzle"). Redirects are not followed by the
 * client (Guzzle's PSR-18 `sendRequest()` disables them whatever the configuration says; the export transport follows
 * them itself), so `Authorization` never reaches another host; TLS verification is always on; statuses come back as
 * responses, not exceptions. The body is not decompressed here: {@see BodyReader} does it, so the size limits count
 * decompressed bytes whatever client is used.
 */
final class GuzzleClientFactory
{
    /** Options every default client has: statuses are responses, no redirects, TLS verified, the body left encoded. */
    private const SAFE_DEFAULTS = [
        RequestOptions::HTTP_ERRORS => false,
        RequestOptions::ALLOW_REDIRECTS => false,
        RequestOptions::VERIFY => true,
        RequestOptions::DECODE_CONTENT => false,
    ];

    private function __construct() {}

    public static function create(TransportOptions $options = new TransportOptions()): ClientInterface
    {
        return new Client(self::config($options));
    }

    /**
     * The client for mutations, which never sends a request twice (CLAUDE.md rule 2, SDK_SPEC 1.4): the server may
     * already have run a mutation whose response was lost.
     *
     * Two resend paths are closed: libcurl resends a request by itself when a reused connection dies before any response
     * byte: every request gets a connection of its own, closed afterwards. Guzzle resends up to three times when
     * libcurl ends without an error code and without a complete response (`CurlFactory::retryFailedRewind()`, e.g. the
     * server sends only a status line and closes): the body is not seekable, so Guzzle's rewind fails and it rejects
     * the request instead.
     */
    public static function createForMutations(TransportOptions $options = new TransportOptions()): ClientInterface
    {
        $handler = HandlerStack::create();
        $handler->push(Middleware::mapRequest(
            static fn(RequestInterface $request): RequestInterface => $request->withBody(
                new NoSeekStream(Utils::streamFor((string) $request->getBody())),
            ),
        ));

        return new Client(['handler' => $handler] + self::mutationConfig($options));
    }

    /**
     * The client for export downloads: a connect timeout, an idle (read) timeout and no total timeout, a streamed body
     * (SDK_SPEC 1.3, ADR 0002). Redirects are followed by {@see \WhiteMarket\Sdk\Export\ExportTransport}, not here.
     */
    public static function createForExports(ExportOptions $options = new ExportOptions()): ClientInterface
    {
        return new Client(self::exportConfig($options));
    }

    /**
     * The client for the SSE live stream: a connect timeout, `read_timeout` as the read slice and no total timeout (the
     * stream is endless), a streamed body. After `LiveOptions::$readSlice` seconds
     * without data a blocked read throws with `timed_out` set in the stream metadata, which {@see ClientStream::readSlice()}
     * turns into an empty string, so the connection can check its own deadline between reads.
     */
    public static function createForLive(LiveOptions $options = new LiveOptions()): ClientInterface
    {
        return new Client(self::liveConfig($options));
    }

    /**
     * Factories for the requests and bodies the default client sends.
     */
    public static function messageFactory(): RequestFactoryInterface&StreamFactoryInterface
    {
        return new HttpFactory();
    }

    /**
     * @internal the request options {@see create()} gives Guzzle, exposed for tests
     *
     * @return array<string, mixed>
     */
    public static function config(TransportOptions $options): array
    {
        return [
            RequestOptions::CONNECT_TIMEOUT => $options->connectTimeout,
            RequestOptions::TIMEOUT => $options->requestTimeout,
        ] + self::SAFE_DEFAULTS;
    }

    /**
     * @internal the request options {@see createForExports()} gives Guzzle, exposed for tests
     *
     * `read_timeout` is the idle timeout: Guzzle applies it in the stream handler, which serves every `stream` request
     * while `allow_url_fopen` is on (`Utils::chooseHandler()`); the cURL handler ignores it. `allow_redirects` is false
     * because the PSR-18 `Client::sendRequest()` forces it off per request whatever is configured here (the export
     * transport follows redirects itself).
     *
     * @return array<string, mixed>
     */
    public static function exportConfig(ExportOptions $options): array
    {
        return [
            RequestOptions::CONNECT_TIMEOUT => $options->connectTimeout,
            RequestOptions::READ_TIMEOUT => $options->idleTimeout,
            RequestOptions::STREAM => true,
        ] + self::SAFE_DEFAULTS;
    }

    /**
     * @internal the request options {@see createForLive()} gives Guzzle, exposed for tests; no `timeout`: it would cut
     *           the endless stream
     *
     * @return array<string, mixed>
     */
    public static function liveConfig(LiveOptions $options): array
    {
        return [
            RequestOptions::CONNECT_TIMEOUT => $options->connectTimeout,
            RequestOptions::READ_TIMEOUT => $options->readSlice,
            RequestOptions::STREAM => true,
        ] + self::SAFE_DEFAULTS;
    }

    /**
     * The options of {@see config()} plus a connection per request (ext-curl is required, so Guzzle uses libcurl).
     *
     * @return array<string, mixed>
     */
    private static function mutationConfig(TransportOptions $options): array
    {
        return self::config($options) + ['curl' => [\CURLOPT_FRESH_CONNECT => true, \CURLOPT_FORBID_REUSE => true]];
    }
}
