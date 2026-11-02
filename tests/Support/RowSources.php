<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Response;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * Body readers over synthetic bodies, and a drain of a {@see RowSource}, for the row source tests.
 */
final class RowSources
{
    private function __construct() {}

    /**
     * @param array<string, string> $headers
     */
    public static function reader(string $body, array $headers = []): BodyReader
    {
        return BodyReader::open(new Response(200, $headers, $body), rejectTrailingData: true);
    }

    /**
     * A reader whose chunks are `$chunkBytes` long (the last one shorter).
     *
     * @param positive-int $chunkBytes
     */
    public static function chunked(string $body, int $chunkBytes): BodyReader
    {
        return BodyReader::open(new Response(200, [], ChunkedStream::of($body, $chunkBytes)), rejectTrailingData: true);
    }

    /**
     * The rows up to the end or the first failure.
     *
     * @return array{list<mixed>, \Throwable|null}
     */
    public static function collect(RowSource $source): array
    {
        $rows = [];
        try {
            while ($source->next()) {
                $rows[] = $source->row();
            }
        } catch (\Throwable $e) {
            return [$rows, $e];
        }

        return [$rows, null];
    }

    /**
     * The rows of a source that is expected to end without a failure.
     *
     * @return list<mixed>
     */
    public static function drain(RowSource $source): array
    {
        [$rows, $failure] = self::collect($source);
        if ($failure !== null) {
            throw $failure;
        }

        return $rows;
    }
}
