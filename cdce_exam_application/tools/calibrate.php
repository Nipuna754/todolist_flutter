<?php

/**
 * Renders a sample application without touching the MIS, so that alignment can
 * be checked after a template change.
 *
 *   php tools/calibrate.php                       sample values
 *   php tools/calibrate.php --guides              outline the target areas
 *   php tools/calibrate.php --long                longest plausible values
 *   php tools/calibrate.php --out=/tmp/check.pdf  where to write
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Cdce\ExamApplication\ApplicationPdf;
use Cdce\ExamApplication\StudentRecord;

$options = getopt('', ['guides', 'long', 'out::']);
$out = $options['out'] ?? __DIR__ . '/../var/calibration.pdf';

$sample = isset($options['long'])
    ? new StudentRecord(
        'AE/BA/21/0000-REPEAT',
        '200012345678',
        'W.A.D.M.K.B. WICKRAMASINGHEPATHIRANNEHELAGE',
        'WELIGAMAGE ARACHCHIGE DON MAHESH KUMARA BANDARA WICKRAMASINGHEPATHIRANNEHELAGE JAYASUNDARA',
    )
    : new StudentRecord(
        'AE/BA/21/1234',
        '199312304567',
        'K.A.N.M. PERERA',
        'KURUKULASURIYA ARACHCHIGE NIMAL MAHINDA PERERA',
    );

$pdf = new ApplicationPdf(__DIR__ . '/../templates/ba_100_level_2026.pdf');
$pdf->render($sample, isset($options['guides']));

@mkdir(dirname($out), 0775, true);
file_put_contents($out, $pdf->toBinaryString());

printf("Wrote %s (%d bytes)%s", $out, filesize($out), PHP_EOL);
