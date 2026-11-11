<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Ownership\Services\OwnershipDoctor;

/** Renders package-owned read-only readiness facts. */
final class OwnershipDoctorCommand extends Command
{
    protected $signature = 'nvl:ownership:doctor {--strict : Treat warnings as failures} {--format=text : Output format: text or json}';

    protected $description = 'Inspect ownership configuration and committed source generations';

    /** Report readiness through the common package diagnostics contract. */
    public function handle(OwnershipDoctor $doctor): int
    {
        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            $this->error('Output format must be text or json.');

            return self::FAILURE;
        }
        $checks = $doctor->inspect();
        $healthy = ! collect($checks)->contains(fn (array $check): bool => ! $check['passed'] && ($this->option('strict') || $check['severity'] === 'error'));
        if ($this->option('format') === 'json') {
            $this->line(json_encode(['healthy' => $healthy, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf('[%s] %s: %s', $check['passed'] ? 'PASS' : strtoupper($check['severity']), $check['key'], $check['message']));
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
