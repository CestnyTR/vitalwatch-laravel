<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Dependencies;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Support\Facades\Cache;

/**
 * Teşhis komutunun tek işi var: yerelde yanlış olanı görünür kılmak.
 * Sessiz kalması, yanlış rapor vermesinden daha kötüdür.
 */
test('eksik yapılandırma bildirilir', function () {
    config()->set('vitalwatch.url', null);
    config()->set('vitalwatch.key', null);
    config()->set('vitalwatch.secret', null);

    $this->artisan('vitalwatch:status')->assertFailed();
});

test('kısa secret yakalanır', function () {
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 32));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('must be 64 characters')
        ->assertFailed();
});

test('şifresiz hub adresi uyarı üretir', function () {
    config()->set('vitalwatch.url', 'http://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    $this->artisan('vitalwatch:status')->assertFailed();
});

test('doğru yapılandırma başarılı döner', function () {
    config()->set('vitalwatch.enabled', true);
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    $this->artisan('vitalwatch:status')->assertSuccessful();
});

test('kapalıyken durum bunu açıkça söyler', function () {
    config()->set('vitalwatch.enabled', false);
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('VITALWATCH_ENABLED=false')
        ->assertFailed();
});

/**
 * Teşhis komutu gönderim sonucunu okumalı, "gönderdim" varsaymamalı.
 *
 * Gerçek bir kurulumda koşulsuz "✓ gönderildi" yazdı, olay hub'a hiç ulaşmadı
 * ve durum ancak saatler sonra veritabanına bakılınca anlaşıldı.
 */
test('gönderim başarısızsa test komutu başarısız döner', function () {
    config()->set('vitalwatch.enabled', true);
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    // Hub'a ulaşılamıyor: gerçek istek yerine başarısız sonuç döndüren istemci.
    app()->instance(HubClient::class, new class extends HubClient
    {
        public function __construct()
        {
            parent::__construct('https://hub.sample', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            return ['sent' => false, 'error' => 'Could not resolve host'];
        }
    });
    // Recorder açılışta çözülüyor; sahte istemciyi görsün.
    app()->forgetInstance(Recorder::class);

    $this->artisan('vitalwatch:status --test')
        ->expectsOutputToContain('Test event NOT SENT')
        ->assertFailed();
});

test('gönderim başarılıysa durum kodu yazılır', function () {
    config()->set('vitalwatch.enabled', true);
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    app()->instance(HubClient::class, new class extends HubClient
    {
        public function __construct()
        {
            parent::__construct('https://hub.sample', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            return ['sent' => true, 'status' => 204];
        }
    });
    // Recorder açılışta çözülüyor; sahte istemciyi görsün.
    app()->forgetInstance(Recorder::class);

    $this->artisan('vitalwatch:status --test')
        ->expectsOutputToContain('HTTP 204')
        ->assertSuccessful();
});

/**
 * "Güncelleme geçti mi" sorusunun cevabı bu satır. Yapılandırma doğru olsa
 * bile eski sürüm eski davranışı sürdürür ve onlarca kurulumda tek tek
 * sunucuya girmeden bunu bilmenin başka yolu yok.
 */
test('kurulu paket sürümü yazdırılır', function () {
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('SDK version')
        ->assertSuccessful();
});

/**
 * Onlarca kurulumu gezen bir döngüde `--test` panele onlarca sahte hata
 * bırakırdı — izleme aracının kendi gürültüsünü üretmesi. Canlılık isteği aynı şeyi
 * kirletmeden kanıtlıyor.
 */
test('vitalwatch seçeneği hata değil canlılık gönderir', function () {
    config()->set('vitalwatch.url', 'https://hub.sample');
    config()->set('vitalwatch.key', 'example-project');
    config()->set('vitalwatch.secret', str_repeat('a', 64));

    $gonderilenler = [];

    app()->instance(Recorder::class, new Recorder(
        new class($gonderilenler) extends HubClient
        {
            public function __construct(public array &$gonderilenler)
            {
                parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
            }

            public function send(array $payload): array
            {
                $this->gonderilenler[] = $payload;

                return ['sent' => true, 'status' => 204];
            }
        },
        ['key' => 'k', 'env' => 'testing', 'release' => null,
            'slow_request_ms' => 1000, 'slow_query_ms' => 500,
            'capture_user_id' => false, 'ignore' => []],
    ));

    $this->artisan('vitalwatch:status --vitalwatch')->assertSuccessful();

    expect($gonderilenler)->toHaveCount(1)
        // Olay taşımaz: panelde hata kaydı oluşmaz.
        ->and($gonderilenler[0]['events'])->toBe([])
        ->and($gonderilenler[0])->not->toHaveKey('kind');
});

/*
| Hub küme dışı ortamı canlılık dahil sessizce atıyor. Komut bunu söylemezse
| kurulum "tamam" görünür ama panele hiçbir şey düşmez.
*/
test('tanınmayan ortamda komut hata verir', function () {
    config(['vitalwatch.env' => 'qa-eu']);

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('is not accepted by the hub')
        ->assertFailed();
});

test('takma ad ortam kabul edilir ve karşılığıyla gösterilir', function () {
    config(['vitalwatch.env' => 'prod']);

    $this->artisan('vitalwatch:status')
        ->expectsOutputToContain('production (prod)')
        ->assertSuccessful();
});

/*
| Bağımlılık listesi satırı ve --deps. Yardımcılar (FakeDepsHub, writeLock,
| depsRecorder) tests/Pest.php'de.
*/
describe('bağımlılık listesi', function () {
    beforeEach(function () {
        Cache::flush();
        Dependencies::forget();
        $this->lock = tempnam(sys_get_temp_dir(), 'vw-lock');
        writeLock($this->lock, [
            ['name' => 'laravel/framework', 'version' => 'v12.1.0'],
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
        ]);
        $this->hub = new FakeDepsHub;
        app()->instance(Recorder::class, depsRecorder($this->hub, $this->lock));
    });

    afterEach(fn () => @unlink($this->lock));

    test('henüz gönderilmediyse paket sayısı ve not sent yet yazılır', function () {
        $this->artisan('vitalwatch:status')
            ->expectsOutputToContain('Dependencies       composer.lock · 2 packages · not sent yet')
            ->assertSuccessful();
    });

    test('son gönderim zamanı yazılır', function () {
        app(Recorder::class)->dependenciesIfDue();
        $this->travel(3)->hours();

        $this->artisan('vitalwatch:status')
            ->expectsOutputToContain('composer.lock · 2 packages · last sent 3 h ago')
            ->assertSuccessful();
    });

    test('composer.lock yoksa söylenir', function () {
        app()->instance(Recorder::class, depsRecorder($this->hub, sys_get_temp_dir().'/vw-none-'.uniqid().'/composer.lock'));

        $this->artisan('vitalwatch:status')
            ->expectsOutputToContain('Dependencies       composer.lock not found')
            ->assertSuccessful();
    });

    test('önbellek okunamazsa dosya bulunamadı denmez, paket sayısı yine yazılır', function () {
        // Yerelde kapalı veritabanı önbelleği gibi: eskiden "composer.lock not found" yazıyordu.
        Cache::partialMock()
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('cache down'));

        $this->artisan('vitalwatch:status')
            ->expectsOutputToContain('composer.lock · 2 packages · last send unknown (cache unavailable)')
            ->doesntExpectOutputToContain('composer.lock not found')
            ->assertSuccessful();
    });

    test('kapalıysa disabled yazılır ve --deps göndermez', function () {
        config(['vitalwatch.dependencies' => false]);

        $this->artisan('vitalwatch:status --deps')
            ->expectsOutputToContain('Dependencies       disabled')
            ->expectsOutputToContain('Dependency list NOT SENT: disabled')
            ->assertFailed();

        expect($this->hub->deps)->toBeEmpty();
    });

    test('--deps listeyi hemen gönderir ve HTTP durumunu yazar', function () {
        $this->artisan('vitalwatch:status --deps')
            ->expectsOutputToContain('Dependency list sent (2 packages, HTTP 204).')
            ->assertSuccessful();

        expect($this->hub->deps)->toHaveCount(1)
            ->and($this->hub->events)->toBeEmpty();
    });

    test('--deps başarısızsa komut başarısız döner', function () {
        $this->hub->up = false;

        $this->artisan('vitalwatch:status --deps')
            ->expectsOutputToContain('Dependency list NOT SENT: Could not resolve host')
            ->assertFailed();
    });

    test('--test bağımlılık listesi göndermez, tek test olayı kalır', function () {
        $this->artisan('vitalwatch:status --test')->assertSuccessful();

        expect($this->hub->deps)->toBeEmpty()
            ->and($this->hub->events)->toHaveCount(1);
    });
});
