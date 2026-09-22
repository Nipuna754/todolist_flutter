<?php

/**
 * Student-facing download page for
 * https://cdce.pdn.ac.lk/tools/apply_examination2/
 *
 * GET  shows the National ID form.
 * POST looks the candidate up in the MIS and streams the filled application.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Cdce\ExamApplication\ApplicationService;
use Cdce\ExamApplication\DownloadException;

$config = cdce_config();
$error = null;
$nicInput = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nicInput = trim((string) ($_POST['nic'] ?? ''));

    if (!cdce_csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please enter your National ID number again.';
    } else {
        try {
            $application = ApplicationService::boot($config)
                ->generate($nicInput, cdce_client_key($config));

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $application->filename . '"');
            header('Content-Length: ' . strlen($application->contents));
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            echo $application->contents;
            exit;
        } catch (DownloadException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[apply_examination2] ' . $e->getMessage());
            $error = 'The application could not be generated just now. Please try again, or contact the CDCE office.';
        }
    }
}

$appTitle = $config->string('app.title', 'Registration and Examination Application');
$period = $config->string('app.exam_period');
$closing = $config->string('app.closing_date');
$email = $config->string('app.office_email');
$phone = $config->string('app.office_phone');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Download Examination Application | CDCE, University of Peradeniya</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="card">
    <header>
        <p class="eyebrow">University of Peradeniya &middot; Centre for Distance and Continuing Education</p>
        <h1><?= e($appTitle) ?></h1>
        <?php if ($period !== ''): ?><p class="period"><?= e($period) ?></p><?php endif; ?>
    </header>

    <p class="lede">
        Enter the National ID number recorded for you at the CDCE. Your registration number and
        name are printed on the application automatically &mdash; check them, complete the
        remaining items by hand, and submit the printed form.
    </p>

    <?php if ($error !== null): ?>
        <p class="error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= e(cdce_csrf_token()) ?>">
        <label for="nic">National ID number</label>
        <input
            id="nic"
            name="nic"
            type="text"
            inputmode="text"
            autocomplete="off"
            spellcheck="false"
            maxlength="20"
            required
            placeholder="931234567V or 199312304567"
            value="<?= e($nicInput) ?>">
        <p class="hint">Either the 9-digit number ending in V or X, or the 12-digit number.</p>
        <button type="submit">Download my application</button>
    </form>

    <section class="notes">
        <h2>Before you submit</h2>
        <ul>
            <li>Print the application on <strong>both sides of a single A4 sheet</strong>.</li>
            <li>If a printed name is wrong, write the correct name in CAPITAL LETTERS in the boxes provided &mdash; it must match your birth certificate.</li>
            <li>Paste the original bank deposit slip in the space on page 2.</li>
            <?php if ($closing !== ''): ?>
                <li>Closing date: <strong><?= e($closing) ?></strong>.</li>
            <?php endif; ?>
        </ul>
        <?php if ($email !== '' || $phone !== ''): ?>
            <p class="contact">
                Problems with your details?
                <?php if ($email !== ''): ?>Email <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php endif; ?>
                <?php if ($email !== '' && $phone !== ''): ?>or<?php endif; ?>
                <?php if ($phone !== ''): ?>call <?= e($phone) ?><?php endif; ?>.
            </p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
