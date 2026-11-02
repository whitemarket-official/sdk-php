<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * The live connection ended for good or a live command failed (SDK_SPEC 1.5 `LiveConnectionError`): a terminal
 * close or disconnect code, an error reply to a command, a server `unsubscribe`, or the reconnect attempts used up.
 *
 * `closeCode` is the Centrifugo close, disconnect or error-reply code (null when there is none). `reason` is
 * the server's reason made safe by {@see ServerText::printable()} (128 bytes of printable ASCII), or a short SDK
 * description such as 'reconnect attempts exhausted'. The message names the code
 * only. Branch on `closeCode`, never on `reason`.
 */
final class LiveConnectionException extends WhiteMarketException
{
    public readonly string $reason;

    public function __construct(public readonly ?int $closeCode, #[\SensitiveParameter] string $reason = '')
    {
        parent::__construct($closeCode === null
            ? 'The live connection failed.'
            : \sprintf('The live connection failed with code %d.', $closeCode));
        $this->reason = ServerText::printable($reason);
    }
}
