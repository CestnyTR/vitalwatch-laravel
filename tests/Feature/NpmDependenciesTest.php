<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Dependencies;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Support\Facades\Cache;

/*
| İkinci liste: package-lock.json. Aynı uç, aynı imza, aynı kimlik alanları;
| composer listesinden bağımsız zamanlanır (kendi durum anahtarı, kendi 24
| saat / özet değişimi, kendi 1 saatlik başarısızlık kilidi).
*/

beforeEach(function () {
    Cache::flush();
    Dependencies::forget();
    $this->lock = tempnam(sys_get_temp_dir(), 'vw-lock');
    $this->npm = tempnam(sys_get_temp_dir(), 'vw-npm');
    writeLock($this->lock, [['name' => 'laravel/framework', 'version' => 'v12.1.0']]);
    writeNpmLock($this->npm, [
        'node_modules/zod' => '3.22.2',
        'node_modules/react' => '19.0.0',
        'node_modules/vite' => ['version' => '6.0.0', 'dev' => true],
    ]);
});

afterEach(function () {
    @unlink($this->lock);
    @unlink($this->npm);
});

/** Gönderilen listeler ekosisteme göre. */
function sentBy(FakeDepsHub $hub, string $ecosystem): array
{
    return array_values(array_filter($hub->deps, fn ($b) => $b['dependencies']['ecosystem'] === $ecosystem));
}

/** Yalnızca npm listesi için hub'ı düşüren sahte. */
class NpmDownHub extends FakeDepsHub
{
    public function sendDependencies(array $payload): array
    {
        $this->deps[] = $payload;

        return $payload['dependencies']['ecosystem'] === 'npm'
            ? ['sent' => false, 'error' => 'HTTP 500']
            : ['sent' => true, 'status' => 204];
    }
}

/** Gerçek imza yolundan geçen, yalnızca ağa çıkmayan istemci. */
function recordingClient(array &$requests): HubClient
{
    return new class('https://hub.test', 'example-project', str_repeat('s', 64), 1, $requests) extends HubClient
    {
        public function __construct(?string $url, ?string $key, ?string $secret, mixed $timeout, private array &$requests)
        {
            parent::__construct($url, $key, $secret, $timeout);
        }

        protected function transmit(string $url, array $headers, string $body): array
        {
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return ['sent' => true, 'status' => 204];
        }
    };
}

test('iki liste aynı kimlik alanlarıyla ayrı gövdelerde gider', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();

    expect($hub->deps)->toHaveCount(2);
    [$composer, $npm] = $hub->deps;

    foreach (['env', 'release', 'php_version', 'sdk_version', 'framework_version'] as $field) {
        expect($npm[$field])->toBe($composer[$field]);
    }

    expect($npm)->not->toHaveKey('events')
        ->and($npm['dependencies'])->toBe([
            'v' => 1,
            'ecosystem' => 'npm',
            'manifest' => 'package-lock.json',
            'hash' => hash('sha256', "react@19.0.0\nzod@3.22.2\n"),
            'packages' => [
                ['name' => 'react', 'version' => '19.0.0'],
                ['name' => 'zod', 'version' => '3.22.2'],
            ],
        ])
        ->and($composer['dependencies']['ecosystem'])->toBe('Packagist');
});

test('composer gönderilmiş, npm zamanı gelmemiş: yalnızca değişen liste gider', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();

    // Yalnızca package-lock.json değişti (npm install sonrası deploy).
    $this->travel(5)->minutes();
    writeNpmLock($this->npm, ['node_modules/zod' => '3.22.3']);
    touch($this->npm, time() + 60);
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();

    expect(sentBy($hub, 'Packagist'))->toHaveCount(1)
        ->and(sentBy($hub, 'npm'))->toHaveCount(2)
        ->and(sentBy($hub, 'npm')[1]['dependencies']['packages'])->toBe([['name' => 'zod', 'version' => '3.22.3']]);
});

test('npm listesi sonradan eklenirse composer yeniden gönderilmez', function () {
    $hub = new FakeDepsHub;

    // 0.3.x gibi: yalnızca composer.
    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();

    expect(sentBy($hub, 'Packagist'))->toHaveCount(1)
        ->and(sentBy($hub, 'npm'))->toHaveCount(1);
});

test('npm gönderimi başarısızsa yalnızca npm bir saat kilitlenir', function () {
    $hub = new NpmDownHub;

    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();
    expect(sentBy($hub, 'npm'))->toHaveCount(1)
        ->and(sentBy($hub, 'Packagist'))->toHaveCount(1);

    // Composer değişirse kilide takılmadan gider.
    writeLock($this->lock, [['name' => 'laravel/framework', 'version' => 'v12.2.0']]);
    touch($this->lock, time() + 60);
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();
    expect(sentBy($hub, 'Packagist'))->toHaveCount(2)
        ->and(sentBy($hub, 'npm'))->toHaveCount(1);

    $this->travel(61)->minutes();
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();
    expect(sentBy($hub, 'npm'))->toHaveCount(2);
});

test('her liste kendi 24 saatiyle yeniden gönderilir', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    $this->travel(12)->hours();
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();
    $this->travel(13)->hours();
    depsRecorder($hub, $this->lock, npmLockPath: $this->npm)->dependenciesIfDue();

    // Composer 25 saat önce gitti → yeniden; npm 13 saat önce → bekler.
    expect(sentBy($hub, 'Packagist'))->toHaveCount(2)
        ->and(sentBy($hub, 'npm'))->toHaveCount(1);
});

test('package-lock.json yoksa sessizce atlanır, composer yine gider', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, npmLockPath: sys_get_temp_dir().'/vw-none-'.uniqid().'/package-lock.json')->dependenciesIfDue();

    expect(sentBy($hub, 'Packagist'))->toHaveCount(1)
        ->and(sentBy($hub, 'npm'))->toBeEmpty();
});

test('composer.lock yoksa npm listesi yine gider', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, sys_get_temp_dir().'/vw-none-'.uniqid().'/composer.lock', npmLockPath: $this->npm)->dependenciesIfDue();

    expect(sentBy($hub, 'npm'))->toHaveCount(1)
        ->and(sentBy($hub, 'Packagist'))->toBeEmpty();
});

test('VITALWATCH_DEPENDENCIES_NPM=false yalnızca npm listesini kapatır', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, npmLockPath: $this->npm, npm: false)->dependenciesIfDue();

    expect(sentBy($hub, 'Packagist'))->toHaveCount(1)
        ->and(sentBy($hub, 'npm'))->toBeEmpty();
});

test('VITALWATCH_DEPENDENCIES=false iki listeyi de kapatır', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, enabled: false, npmLockPath: $this->npm)->dependenciesIfDue();

    expect($hub->deps)->toBeEmpty();
});

test('512 KB üstü npm gövdesi gönderilmez, composer etkilenmez', function () {
    $packages = [];

    for ($i = 0; $i < 5000; $i++) {
        $packages['node_modules/'.str_repeat('n', 100).$i] = '1.0.0';
    }

    writeNpmLock($this->npm, $packages);

    $requests = [];
    $client = recordingClient($requests);

    $recorder = new Recorder($client, [
        'key' => 'example-project', 'env' => 'production', 'release' => null,
        'dependencies' => true, 'lock_path' => $this->lock, 'npm_lock_path' => $this->npm,
    ]);

    $results = $recorder->sendDependencies();

    expect($results['package-lock.json'])->toMatchArray(['sent' => false, 'error' => 'body-too-large', 'packages' => 5000])
        ->and($results['composer.lock']['sent'])->toBeTrue()
        ->and($requests)->toHaveCount(1);

    // İmza yolu ortak: gövdenin HMAC'i başlıkta.
    $timestamp = substr(collect($requests[0]['headers'])->first(fn ($h) => str_starts_with($h, 'X-VitalWatch-Timestamp: ')), 24);
    expect($requests[0]['url'])->toBe('https://hub.test/api/i/example-project/deps')
        ->and($requests[0]['headers'])->toContain('X-VitalWatch-Signature: sha256='.hash_hmac('sha256', $timestamp.'.'.$requests[0]['body'], str_repeat('s', 64)));
});

test('npm listesi imzalı /deps ucuna gider', function () {
    $requests = [];
    $client = recordingClient($requests);

    (new Recorder($client, [
        'key' => 'example-project', 'env' => 'production', 'release' => null,
        'dependencies' => true, 'lock_path' => null, 'npm_lock_path' => $this->npm,
    ]))->dependenciesIfDue();

    expect($requests)->toHaveCount(1);
    $request = $requests[0];
    $timestamp = substr(collect($request['headers'])->first(fn ($h) => str_starts_with($h, 'X-VitalWatch-Timestamp: ')), 24);

    expect($request['url'])->toBe('https://hub.test/api/i/example-project/deps')
        ->and($request['headers'])->toBe($client->headers($request['body'], $timestamp))
        ->and(json_decode($request['body'], true)['dependencies']['manifest'])->toBe('package-lock.json');
});

/** Sağlayıcının Recorder'a verdiği yapılandırma. */
function npmRecorderConfig(): array
{
    return (new ReflectionProperty(Recorder::class, 'config'))->getValue(app(Recorder::class));
}

test('sağlayıcı package-lock.json yolunu ve npm bayrağını kurar', function () {
    $this->app->forgetInstance(Recorder::class);

    expect(npmRecorderConfig()['npm_lock_path'])->toBe(base_path('package-lock.json'))
        ->and(npmRecorderConfig()['dependencies_npm'])->toBeTrue();

    config(['vitalwatch.dependencies_npm' => false]);
    $this->app->forgetInstance(Recorder::class);

    expect(npmRecorderConfig()['dependencies_npm'])->toBeFalse()
        ->and(npmRecorderConfig()['dependencies'])->toBeTrue();
});
