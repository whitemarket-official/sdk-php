<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;
use WhiteMarket\Sdk\Transport\ClientResponse;

/**
 * The body of a 200 export response, not yet read, with the validators the server sent. Read it once, either whole and
 * bounded ({@see read()}) or as decoded chunks ({@see reader()}).
 *
 * @internal returned by {@see ExportTransport}
 */
final readonly class ExportBody
{
    public function __construct(
        private ClientResponse $response,
        public string $url,
        public ?string $etag,
        public ?string $lastModified,
    ) {}

    /**
     * The decoded body, which must not exceed `$maxBytes` decoded bytes.
     *
     * @throws TransportException the body is over the limit, not valid gzip, cut short or unreadable
     */
    public function read(int $maxBytes): string
    {
        return BodyReader::readAll($this->response, $maxBytes, 'maxBodyBytes', rejectTrailingData: true);
    }

    /**
     * A reader of the decoded body, with no size limit; its `next()` throws when the body is not valid gzip, is cut
     * short or is unreadable.
     *
     * @throws TransportException unsupported `Content-Encoding`
     */
    public function reader(): BodyReader
    {
        return BodyReader::open($this->response, rejectTrailingData: true);
    }
}
