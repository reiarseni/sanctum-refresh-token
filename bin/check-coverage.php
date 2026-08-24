#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fails the build when line coverage falls below a floor.
 *
 * Kept as a tiny script rather than a dependency: it reads one number out of a
 * Clover report and compares it, and a package that ships a security control
 * should not add a build-time dependency to do that.
 *
 * Usage: php bin/check-coverage.php build/logs/clover.xml 80
 */
$report = $argv[1] ?? null;
$minimum = (float) ($argv[2] ?? 0);

if ($report === null || ! is_file($report)) {
    fwrite(STDERR, 'Coverage report not found: '.var_export($report, true)."\n");

    exit(1);
}

$xml = simplexml_load_file($report);

if ($xml === false) {
    fwrite(STDERR, "Unable to parse the coverage report at {$report}.\n");

    exit(1);
}

$metrics = $xml->project->metrics ?? null;

if ($metrics === null) {
    fwrite(STDERR, "The coverage report at {$report} carries no project metrics.\n");

    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "The coverage report records no statements at all.\n");

    exit(1);
}

$percentage = round($covered / $statements * 100, 2);

printf("Line coverage: %.2f%% (%d/%d statements), minimum %.2f%%\n", $percentage, $covered, $statements, $minimum);

if ($percentage + 0.001 < $minimum) {
    fwrite(STDERR, sprintf("Coverage %.2f%% is below the required %.2f%%.\n", $percentage, $minimum));

    exit(1);
}

exit(0);
