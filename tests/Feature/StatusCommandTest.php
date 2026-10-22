<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;

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
