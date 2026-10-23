<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Tests\TestCase;
use CestnyTR\VitalWatch\Transport\HubClient;

pest()->extend(TestCase::class)->in('Feature');

/*
| Bağımlılık listesi testlerinin ortak yardımcıları (DependenciesTest,
| StatusCommandTest).
*/

/** Sahte hub: olayları ve bağımlılık listelerini ayrı biriktirir. */
class FakeDepsHub extends HubClient
{
    public array $events = [];

    public array $deps = [];

    public bool $up = true;

    public function __construct()
    {
        parent::__construct('https://x.test', 'example-project', str_repeat('s', 64), 1);
    }

    public function send(array $payload): array
    {
        $this->events[] = $payload;

        return ['sent' => true, 'status' => 204];
    }

    public function sendDependencies(array $payload): array
    {
        $this->deps[] = $payload;

        return $this->up ? ['sent' => true, 'status' => 204] : ['sent' => false, 'error' => 'Could not resolve host'];
    }
}

function writeLock(string $path, array $packages): void
{
    file_put_contents($path, json_encode([
        'packages' => $packages,
        'packages-dev' => [['name' => 'pestphp/pest', 'version' => 'v3.0.0']],
    ]));
}

function depsRecorder(FakeDepsHub $hub, ?string $lockPath, bool $enabled = true): Recorder
{
    return new Recorder($hub, [
        'key' => 'example-project', 'env' => 'production', 'release' => 'abc123',
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
        'dependencies' => $enabled, 'lock_path' => $lockPath,
    ]);
}
