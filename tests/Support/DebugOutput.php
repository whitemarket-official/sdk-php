<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * Every way a developer can dump a value, so a test can assert a secret appears in none of them.
 */
final class DebugOutput
{
    private function __construct() {}

    /**
     * @return array{print_r: string, var_export: string, json_encode: string, var_dump: string} the output keyed by the dumping function
     */
    public static function all(mixed $value): array
    {
        ob_start();
        var_dump($value);
        $varDump = (string) ob_get_clean();

        $json = json_encode($value);

        return [
            'print_r' => print_r($value, true),
            'var_export' => var_export($value, true),
            'json_encode' => $json === false ? '' : $json,
            'var_dump' => $varDump,
        ];
    }
}
