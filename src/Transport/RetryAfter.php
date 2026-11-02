<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Parses a `Retry-After` header value: delay-seconds or an HTTP-date (RFC 9110 10.2.3).
 *
 * @internal called by the transport
 */
final class RetryAfter
{
    private const IMF_FIXDATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    /** Obsolete RFC 850 form, two-digit year (RFC 9110 5.6.7). */
    private const RFC_850_FORMAT = 'l, d-M-y H:i:s \G\M\T';

    /** Obsolete asctime() form, space-padded day (RFC 9110 5.6.7). */
    private const ASCTIME_FORMAT = 'D M j H:i:s Y';

    /** Returned for a delay-seconds value too long for int: "very long"; the retry policy caps it anyway. */
    private const SATURATED_SECONDS = 999_999_999;

    private function __construct() {}

    /**
     * Seconds to wait, `0` for a date in the past, `null` for a missing or unparsable value.
     */
    public static function seconds(string $header, \DateTimeImmutable $now): ?int
    {
        $value = trim($header);
        if ($value === '') {
            return null;
        }
        if (strspn($value, '0123456789') === \strlen($value)) {
            return \strlen($value) > 9 ? self::SATURATED_SECONDS : (int) $value;
        }

        $date = self::httpDate($value);
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Each format is parsed and formatted back: a value that does not round-trip (wrong weekday, padding) is rejected.
     */
    private static function httpDate(string $value): ?\DateTimeImmutable
    {
        foreach ([self::IMF_FIXDATE_FORMAT, self::RFC_850_FORMAT, self::ASCTIME_FORMAT] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if ($date !== false && self::canonical($date, $format) === $value) {
                return $date;
            }
        }

        return null;
    }

    private static function canonical(\DateTimeImmutable $date, string $format): string
    {
        if ($format !== self::ASCTIME_FORMAT) {
            return $date->format($format);
        }

        // asctime pads a one-digit day with a space ("Nov  6"), which no format character produces.
        return $date->format('D M ') . str_pad($date->format('j'), 2, ' ', STR_PAD_LEFT) . $date->format(' H:i:s Y');
    }
}
