<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Support\Facades\Cache;

/**
 * Hub bir kurulumun çalıştığını yalnızca hata gelmesinden anlıyordu; hatasız
 * uygulama "kurulum bozuk" görünüyordu. Kanıt artık isteğin kendisi.
 */
function vitalwatchRecorder(array &$gonderilenler): Recorder
{
    $client = new class($gonderilenler) extends HubClient
    {
        public function __construct(public array &$gonderilenler)
        {
            parent::__construct('https://x.test', 'example-project', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    return new Recorder($client, [
        'key' => 'example-project',
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);
}

beforeEach(fn () => Cache::flush());

test('canlılık isteği olay taşımaz', function () {
    $gonderilenler = [];

    $result = vitalwatchRecorder($gonderilenler)->heartbeat();

    expect($result['sent'])->toBeTrue()
        ->and($gonderilenler)->toHaveCount(1)
        // Boş toplu istek: hub sıfır olay işler ama canlılığı damgalar.
        ->and($gonderilenler[0]['events'])->toBe([])
        // Hangi eksenin canlı olduğu env ve kaynaktan okunuyor.
        ->and($gonderilenler[0]['env'])->toBe('testing');
});

/**
 * Canlılık isteğe bağlı: her istekte gerçek bir HTTP çağrısı yapmak izlenen
 * uygulamayı yavaşlatırdı. Aradaki çağrılar yalnızca önbellek okur.
 */
test('canlılık sekiz saatte bir gönderilir', function () {
    $gonderilenler = [];
    $recorder = vitalwatchRecorder($gonderilenler);

    $recorder->heartbeatIfDue();
    $recorder->heartbeatIfDue();
    $recorder->heartbeatIfDue();

    expect($gonderilenler)->toHaveCount(1);
});

test('pencere dolunca yeniden gönderilir', function () {
    $gonderilenler = [];
    $recorder = vitalwatchRecorder($gonderilenler);

    $recorder->heartbeatIfDue();
    Cache::flush();
    $this->travel(2)->minutes();
    $recorder->heartbeatIfDue();

    expect($gonderilenler)->toHaveCount(2);
});

/*
| Kuyruk döngüsü boştayken saniyeler içinde tekrar tetikleniyor. Önbellek
| boşalmış olsa bile dakika dolmadan tekrar bakılmaz.
*/
test('bir dakika içinde önbelleğe tekrar bakılmaz', function () {
    $gonderilenler = [];
    $recorder = vitalwatchRecorder($gonderilenler);

    $recorder->heartbeatIfDue();
    Cache::flush();
    $this->travel(30)->seconds();
    $recorder->heartbeatIfDue();

    expect($gonderilenler)->toHaveCount(1);
});

/**
 * Önbellek anahtarı proje bazlı: aynı cache deposunu paylaşan iki uygulama
 * birbirinin nabzını bastırmamalı.
 */
test('farklı projeler birbirinin nabzını bastırmaz', function () {
    $a = [];
    $b = [];

    $first = vitalwatchRecorder($a);
    $second = new Recorder(
        new class extends HubClient
        {
            public array $gonderilenler = [];

            public function __construct()
            {
                parent::__construct('https://x.test', 'second-project', str_repeat('s', 64), 1);
            }

            public function send(array $payload): array
            {
                $this->gonderilenler[] = $payload;

                return ['sent' => true, 'status' => 204];
            }
        },
        ['key' => 'second-project', 'env' => 'testing', 'release' => null,
            'slow_request_ms' => 1000, 'slow_query_ms' => 500,
            'capture_user_id' => false, 'ignore' => []],
    );

    $first->heartbeatIfDue();
    $second->heartbeatIfDue();

    expect($a)->toHaveCount(1)
        ->and(Cache::has('vitalwatch:heartbeat:example-project'))->toBeTrue()
        ->and(Cache::has('vitalwatch:heartbeat:second-project'))->toBeTrue();
});
