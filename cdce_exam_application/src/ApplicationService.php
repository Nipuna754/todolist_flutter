<?php

declare(strict_types=1);

namespace Cdce\ExamApplication;

/**
 * The one entry point the web layer needs: a NIC number in, a finished PDF out.
 */
final class ApplicationService
{
    public function __construct(
        private readonly Config $config,
        private readonly MisRepository $repository,
        private readonly RateLimiter $rateLimiter,
        private readonly ?AuditLog $log = null,
    ) {
    }

    public static function boot(Config $config): self
    {
        return new self(
            $config,
            MisRepository::connect($config),
            RateLimiter::fromConfig($config),
            new AuditLog($config->string('log_file', dirname(__DIR__) . '/var/download.log')),
        );
    }

    /**
     * @throws DownloadException with a message that is safe to show a student.
     */
    public function generate(string $rawNic, string $clientKey): GeneratedApplication
    {
        if ($this->rateLimiter->tooManyAttempts($clientKey)) {
            $minutes = (int) ceil($this->rateLimiter->secondsUntilReset($clientKey) / 60);
            throw new DownloadException(sprintf(
                'Too many attempts from this connection. Please try again in %d minute%s.',
                max(1, $minutes),
                $minutes === 1 ? '' : 's'
            ));
        }

        // Counted before the lookup, so a failed guess costs the same as a hit.
        $this->rateLimiter->record($clientKey);

        try {
            $nic = Nic::parse($rawNic);
        } catch (\InvalidArgumentException $e) {
            throw new DownloadException($e->getMessage(), previous: $e);
        }

        try {
            $student = $this->repository->findEligibleStudent($nic);
        } catch (AmbiguousStudentException $e) {
            $this->log?->write('ambiguous', $nic->masked());
            throw new DownloadException(
                'Your National ID number matches more than one record. Please contact the CDCE office so that it can be corrected.',
                previous: $e
            );
        } catch (\PDOException $e) {
            $this->log?->write('mis-error', $nic->masked(), $e->getMessage());
            throw new DownloadException(
                'The student records system could not be reached. Please try again shortly.',
                previous: $e
            );
        }

        if ($student === null) {
            $this->log?->write('not-found', $nic->masked());
            throw new DownloadException(
                'No candidate eligible for this examination was found under that National ID number. '
                . 'Check the number you entered, and contact the CDCE office if it is correct.'
            );
        }

        $missing = $student->missingFields();
        if ($missing !== []) {
            $this->log?->write('incomplete', $nic->masked(), implode(', ', $missing));
            throw new DownloadException(sprintf(
                'Your record is missing: %s. The application cannot be printed until the CDCE office completes it.',
                implode(', ', $missing)
            ));
        }

        $pdf = new ApplicationPdf($this->config->string(
            'template',
            dirname(__DIR__) . '/templates/ba_100_level_2026.pdf'
        ));
        $pdf->render($student);

        $this->log?->write('issued', $nic->masked(), $student->registrationNo);

        return new GeneratedApplication(
            sprintf('BA-100-Level-2026-%s.pdf', $student->slug()),
            $pdf->toBinaryString(),
            $student,
        );
    }
}
