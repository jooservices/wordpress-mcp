<?php

declare(strict_types=1);

$min = (int) ($_SERVER['argv'][2] ?? 70);
$file = $_SERVER['argv'][1] ?? '';
$xml = simplexml_load_file($file);

if ($xml === false) {
    fwrite(STDERR, "Cannot read coverage file: {$file}\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? ($covered / $statements) * 100 : 100;

echo sprintf("Coverage: %.2f%% (minimum %d%%)\n", $percent, $min);

if ($percent < $min) {
    exit(1);
}
