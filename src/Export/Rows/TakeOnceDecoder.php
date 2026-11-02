<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\JsonDecoder\InvalidResult;
use JsonMachine\JsonDecoder\ItemDecoder;
use JsonMachine\JsonDecoder\ValidResult;

/**
 * json-machine's `ExtJsonDecoder` (arrays, `JSON_BIGINT_AS_STRING`) with every decoded value boxed in a
 * {@see TakeOnceRow}.
 *
 * @internal
 */
final readonly class TakeOnceDecoder implements ItemDecoder
{
    private ExtJsonDecoder $decoder;

    public function __construct()
    {
        $this->decoder = new ExtJsonDecoder(true, 512, \JSON_BIGINT_AS_STRING);
    }

    /**
     * @param mixed $jsonValue the JSON text of one value (the interface leaves the parameter untyped)
     */
    public function decode(#[\SensitiveParameter] $jsonValue): InvalidResult|ValidResult
    {
        $result = $this->decoder->decode($jsonValue);

        return $result instanceof ValidResult ? new ValidResult(new TakeOnceRow($result->getValue())) : $result;
    }
}
