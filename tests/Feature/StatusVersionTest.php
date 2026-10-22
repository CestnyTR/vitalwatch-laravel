<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Packagist;
use CestnyTR\VitalWatch\Support\Release;

/*
| `vitalwatch:status`: sürüm etiketi ve Packagist güncelleme denetimi.
|
| Güncelleme denetimi yalnızca bilgi verir; eski sürüm yapılandırma hatası
| değil, çıkış kodu değişmez.
*/

function vitalwatchPackagist(?string $body, string $installed = '0.2.2'): Packagist
{
    return new class($body, $installed) extends Packagist
    {
        public int $calls = 0;

        public function __construct(private ?string $body, private string $version) {}

        public function installed(): string
        {
            return $this->version;
        }

        protected function fetch(): ?string
        {
            $this->calls++;

            return $this->body;
        }
    };
}

function vitalwatchPackagistBody(array $versions): string
{
    return json_encode(['packages' => ['cestnytr/vitalwatch-laravel' => array_map(
        fn ($v) => ['name' => 'cestnytr/vitalwatch-laravel', 'version' => $v],
        $versions,
    )]]);
}

beforeEach(function () {
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));
    config()->set('vitalwatch.env', 'production');
});

afterEach(function () {
    putenv('VITALWATCH_STATUS_OFFLINE');
    putenv('GIT_COMMIT');
});

test('en yeni kararlı sürüm seçilir: sayısal karşılaştırma, v öneki, dev ve ön sürüm atlanır', function () {
    expect(Packagist::pickLatest(json_decode(vitalwatchPackagistBody([
        'dev-main', 'v0.9.9', 'v0.10.0', '1.0.0-beta1', 'v0.10.1-RC1', '0.2.3', 'v1.0.x-dev',
    ]), true)))->toBe('0.10.0')
        ->and(Packagist::pickLatest([]))->toBeNull()
        ->and(Packagist::pickLatest(['packages' => ['cestnytr/vitalwatch-laravel' => [['version' => 'dev-main']]]]))->toBeNull();
});

test('daha yeni sürüm varsa uyarı yazılır, komut yine başarılı', function () {
    app()->instance(Packagist::class, vitalwatchPackagist(vitalwatchPackagistBody(['v0.2.2', 'v0.10.1', 'v0.9.0']), 'v0.2.2'));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('Latest version     0.10.1')
        ->expectsOutputToContain('Update available: composer require cestnytr/vitalwatch-laravel:^0.10')
        ->assertSuccessful();
});

test('kurulu sürüm güncelse uyarı yok', function (string $installed) {
    app()->instance(Packagist::class, vitalwatchPackagist(vitalwatchPackagistBody(['v0.2.2', 'v0.2.3']), $installed));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('Latest version     0.2.3')
        ->doesntExpectOutputToContain('Update available')
        ->assertSuccessful();
})->with([['v0.2.3'], ['0.2.3'], ['0.3.0'], ['unknown'], ['dev-main']]);

test('ağ hatasında denetlenemedi yazılır, komut başarısız sayılmaz', function () {
    app()->instance(Packagist::class, vitalwatchPackagist(null));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('Latest version     could not check')
        ->assertSuccessful();
});

test('bozuk yanıt denetlenemedi sayılır', function () {
    app()->instance(Packagist::class, vitalwatchPackagist('<html>'));

    $this->artisan('vitalwatch:status')->expectsOutputToContain('could not check')->assertSuccessful();
});

test('VITALWATCH_STATUS_OFFLINE=1 denetimi atlar', function () {
    $fake = vitalwatchPackagist(vitalwatchPackagistBody(['v9.0.0']));
    app()->instance(Packagist::class, $fake);
    putenv('VITALWATCH_STATUS_OFFLINE=1');

    $this->artisan('vitalwatch:status')
        ->doesntExpectOutputToContain('Latest version')
        ->assertSuccessful();

    expect($fake->calls)->toBe(0);
});

test('sürüm etiketi ve kaynağı gösterilir', function () {
    config()->set('vitalwatch.release', 'v2.4.1');

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('Release tag        v2.4.1 (source: VITALWATCH_RELEASE)')
        ->assertSuccessful();

    config()->set('vitalwatch.release', null);
    putenv('GIT_COMMIT='.str_repeat('ab', 20));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('Release tag        abababababab (source: GIT_COMMIT)')
        ->assertSuccessful();
});

test('Recorder sürüm etiketini otomatik bulur, yapılandırma üstün gelir', function () {
    $release = fn () => (fn () => $this->config['release'])->call(app(Recorder::class));

    config()->set('vitalwatch.release', null);
    putenv('GIT_COMMIT='.str_repeat('cd', 20));
    app()->forgetInstance(Recorder::class);

    expect($release())->toBe('cdcdcdcdcdcd');

    config()->set('vitalwatch.release', 'manually-set');
    app()->forgetInstance(Recorder::class);

    expect($release())->toBe('manually-set')
        ->and(Release::detect(base_path(), 'manually-set')['source'])->toBe('VITALWATCH_RELEASE');
});
