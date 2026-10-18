<?php

declare(strict_types=1);

it('reports unowned readiness through the standard strict JSON doctor contract', function (): void {
    $this->artisan('nvl:ownership:doctor', ['--strict' => true, '--format' => 'json'])->assertSuccessful()->expectsOutputToContain('"healthy": true');
});

it('rejects an unsupported doctor format', function (): void {
    $this->artisan('nvl:ownership:doctor', ['--format' => 'xml'])->assertFailed();
});
