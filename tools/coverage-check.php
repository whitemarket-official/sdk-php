<?php

declare(strict_types=1);

/*
 * Fails when line coverage in a Clover report is below the threshold.
 * Usage: php tools/coverage-check.php <clover.xml> <min-percent>
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/coverage-check.php <clover.xml> <min-percent>\n");
    exit(2);
}

$xml = @simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, "Cannot read Clover report {$argv[1]}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$min = (float) $argv[2];
$percent = $total === 0 ? 100.0 : $covered / $total * 100;

printf("Line coverage: %.2f %% (%d/%d), required: %.2f %%\n", $percent, $covered, $total, $min);
if ($percent < $min) {
    fwrite(STDERR, "ERROR: line coverage is below the threshold\n");
    exit(1);
}
