<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Optional ClamAV scan for chat uploads (config/security.php → uploads).
 * OFF by default — Laravel's `mimes:` validation already content-sniffs the
 * real file type, so this is an extra layer for hosts with ClamAV installed.
 *
 * When enabled the scan is FAIL-CLOSED: a missing binary or scanner error
 * rejects the upload with a clear message instead of silently skipping — a
 * security control that quietly stops working is worse than none.
 */
class UploadScanner
{
    public function enabled(): bool
    {
        return (bool) config('security.uploads.scan', false);
    }

    /**
     * Throws a RuntimeException with a user-safe message when the file is
     * infected or the scanner is unavailable/errored. No-op when disabled.
     */
    public function assertClean(UploadedFile $file): void
    {
        if (! $this->enabled()) {
            return;
        }

        $scanner = (string) config('security.uploads.scanner', 'clamscan');
        $timeout = (int) config('security.uploads.scan_timeout', 30);

        $result = Process::timeout($timeout)->run([
            $scanner,
            '--no-summary',
            $file->getRealPath(),
        ]);

        // clamscan exit codes: 0 = clean, 1 = infected, 2+ = scanner error.
        if ($result->exitCode() === 0) {
            return;
        }

        if ($result->exitCode() === 1) {
            Log::warning('Upload rejected by virus scan', [
                'name' => $file->getClientOriginalName(),
                'output' => trim($result->output()),
            ]);

            throw new RuntimeException('This file was rejected by the virus scanner.');
        }

        Log::error('Upload virus scan unavailable — rejecting upload (fail-closed)', [
            'scanner' => $scanner,
            'exit_code' => $result->exitCode(),
            'error' => trim($result->errorOutput()),
        ]);

        throw new RuntimeException('File scanning is unavailable right now — the upload was not accepted. Please try again later.');
    }
}
