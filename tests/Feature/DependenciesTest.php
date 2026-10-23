<?php

use CestnyTR\VitalWatch\Http\Middleware\MeasureRequest;
use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Dependencies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/*
| Bağımlılık listesi canlılıkla aynı yoldan gider: terminate (yanıttan sonra),
| kuyruk ve zamanlayıcı. Günde bir kez ve liste değişince.
*/

beforeEach(function () {
    Cache::flush();
    Dependencies::forget();
    $this->lock = tempnam(sys_get_temp_dir(), 'vw-lock');
    writeLock($this->lock, [
        ['name' => 'laravel/framework', 'version' => 'v12.1.0'],
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
        ['name' => 'acme/fork', 'version' => 'dev-main'],
    ]);
});

afterEach(fn () => @unlink($this->lock));

test('gövde sözleşmeye uygun: kimlik alanları ve yalnızca üretim paketleri', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();

    expect($hub->deps)->toHaveCount(1);
    $body = $hub->deps[0];

    expect($body['env'])->toBe('production')
        ->and($body['release'])->toBe('abc123')
        ->and($body['php_version'])->toBe(PHP_VERSION)
        ->and($body)->toHaveKeys(['sdk_version', 'framework_version'])
        ->and($body)->not->toHaveKey('events')
        ->and($body['dependencies'])->toBe([
            'v' => 1,
            'ecosystem' => 'Packagist',
            'manifest' => 'composer.lock',
            'hash' => hash('sha256', "guzzlehttp/guzzle@7.8.1\nlaravel/framework@12.1.0\n"),
            'packages' => [
                ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
                ['name' => 'laravel/framework', 'version' => '12.1.0'],
            ],
        ]);
});

test('24 saat içinde aynı liste yeniden gönderilmez, süre dolunca gönderilir', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    $this->travel(23)->hours();
    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    expect($hub->deps)->toHaveCount(1);

    $this->travel(2)->hours();
    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    expect($hub->deps)->toHaveCount(2);
});

test('deploy sonrası liste değişince hemen gönderilir', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();

    $this->travel(5)->minutes();
    writeLock($this->lock, [['name' => 'laravel/framework', 'version' => 'v12.2.0']]);
    touch($this->lock, time() + 60);
    depsRecorder($hub, $this->lock)->dependenciesIfDue();

    expect($hub->deps)->toHaveCount(2)
        ->and($hub->deps[1]['dependencies']['packages'])->toBe([['name' => 'laravel/framework', 'version' => '12.2.0']]);
});

test('dosyaya dokunulup liste aynı kalırsa yeniden gönderilmez', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    touch($this->lock, time() + 60);
    depsRecorder($hub, $this->lock)->dependenciesIfDue();

    expect($hub->deps)->toHaveCount(1);
});

test('hub erişilemezse sessizce vazgeçilir ve bir saat tekrar denenmez', function () {
    $hub = new FakeDepsHub;
    $hub->up = false;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    expect($hub->deps)->toHaveCount(1);

    $hub->up = true;
    $this->travel(61)->minutes();
    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    expect($hub->deps)->toHaveCount(2);
});

test('VITALWATCH_DEPENDENCIES=false iken gönderilmez', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock, enabled: false)->dependenciesIfDue();

    expect($hub->deps)->toBeEmpty();
});

test('composer.lock yoksa hiçbir şey yapılmaz', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, sys_get_temp_dir().'/vw-none-'.uniqid().'/composer.lock')->dependenciesIfDue();
    depsRecorder($hub, null)->dependenciesIfDue();

    expect($hub->deps)->toBeEmpty();
});

test('farklı ortamlar birbirinin listesini bastırmaz', function () {
    $hub = new FakeDepsHub;

    depsRecorder($hub, $this->lock)->dependenciesIfDue();
    (new Recorder($hub, [
        'key' => 'example-project', 'env' => 'staging', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500, 'capture_user_id' => false, 'ignore' => [],
        'dependencies' => true, 'lock_path' => $this->lock,
    ]))->dependenciesIfDue();

    expect($hub->deps)->toHaveCount(2)
        ->and($hub->deps[1]['env'])->toBe('staging');
});

/*
| İsteği bekletmez: handle() hiçbir şey göndermez, liste terminate'te —
| yanıt kullanıcıya gittikten sonra — gider.
*/
test('liste istek sırasında değil terminate aşamasında gönderilir', function () {
    $hub = new FakeDepsHub;
    $recorder = depsRecorder($hub, $this->lock);
    $middleware = new MeasureRequest($recorder);
    $request = Request::create('/products', 'GET');

    $response = $middleware->handle($request, function () use ($hub) {
        // Uygulama kodu çalışırken henüz hiçbir şey gitmemiş olmalı.
        expect($hub->deps)->toBeEmpty();

        return new Response('ok');
    });

    expect($hub->deps)->toBeEmpty();

    $middleware->terminate($request, $response);

    expect($hub->deps)->toHaveCount(1)
        // Canlılık da aynı yerde gider.
        ->and(array_filter($hub->events, fn ($e) => isset($e['events'])))->toHaveCount(1);
});

/** Sağlayıcının Recorder'a verdiği yapılandırma. */
function recorderConfig(): array
{
    return (new ReflectionProperty(Recorder::class, 'config'))->getValue(app(Recorder::class));
}

test('sağlayıcı varsayılan olarak listeyi açık ve composer.lock yoluyla kurar', function () {
    $this->app->forgetInstance(Recorder::class);

    expect(recorderConfig()['dependencies'])->toBeTrue()
        ->and(recorderConfig()['lock_path'])->toBe(base_path('composer.lock'));
});

test('VITALWATCH_ENABLED=false iken sağlayıcı listeyi kapalı kurar', function () {
    config(['vitalwatch.enabled' => false]);
    $this->app->forgetInstance(Recorder::class);

    expect(recorderConfig()['dependencies'])->toBeFalse();
});

test('sağlayıcı VITALWATCH_DEPENDENCIES ayarını okur', function () {
    config(['vitalwatch.dependencies' => false]);
    $this->app->forgetInstance(Recorder::class);

    expect(recorderConfig()['dependencies'])->toBeFalse();
});
