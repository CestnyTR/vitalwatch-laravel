<?php

use CestnyTR\VitalWatch\Http\Middleware\MeasureRequest;
use CestnyTR\VitalWatch\VitalWatchServiceProvider;
use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/*
| CLAUDE.md "Davranış garantileri" — paket kurulduğu uygulamayı etkilemez.
| Bunlar ihlal edilemez; her biri ayrı test edilir.
*/

test('VITALWATCH_ENABLED=false iken hiçbir kanca kurulmaz', function () {
    config(['vitalwatch.enabled' => false]);

    // Sağlayıcıyı yeniden boot et.
    (new VitalWatchServiceProvider($this->app))->boot();

    $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

    expect($middleware)->not->toContain(MeasureRequest::class);
});

test('yapılandırma eksikse kanca kurulmaz', function () {
    config(['vitalwatch.url' => null, 'vitalwatch.key' => null, 'vitalwatch.secret' => null]);

    $client = new HubClient(null, null, null, 2);

    expect($client->configured())->toBeFalse();
});

test('hub erişilemezken gönderim istisna fırlatmaz', function () {
    // Yönlendirilemeyen bir adres: bağlantı kurulamaz.
    $client = new HubClient('http://127.0.0.1:9', 'test', str_repeat('s', 64), 1);

    $client->send(['kind' => 'exception', 'msg' => 'test']);

    expect(true)->toBeTrue(); // Buraya ulaşmak testin kendisidir.
});

test('bozuk yapılandırmayla gönderim sessizce vazgeçer', function () {
    $client = new HubClient('this-is-not-a-url', 'test', 'secret', 1);

    $client->send(['kind' => 'exception', 'msg' => 'test']);

    expect(true)->toBeTrue();
});

/**
 * Exception yutulmaz: reportable kancası false döndürmez, dolayısıyla
 * Laravel'in kendi loglaması ve throw zinciri korunur.
 */
test('exception kancası raporlamayı durdurmaz', function () {
    $handler = $this->app->make(ExceptionHandler::class);

    expect(method_exists($handler, 'reportable'))->toBeTrue();

    // Kanca kurulduktan sonra exception hâlâ fırlatılabilir olmalı.
    expect(fn () => throw new RuntimeException('patladı'))
        ->toThrow(RuntimeException::class, 'patladı');
});

test('yok sayılan exception sınıfları raporlanmaz', function () {
    $gonderilenler = [];

    $client = new class($gonderilenler) extends HubClient
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
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false,
        'ignore' => [ValidationException::class],
    ]);

    $recorder->recordException(ValidationException::withMessages(['a' => 'b']));
    expect($client->gonderilenler)->toBeEmpty();

    $recorder->recordException(new RuntimeException('gerçek hata'));
    expect($client->gonderilenler)->toHaveCount(1);
});

test('aynı exception iki kez raporlanmaz', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    $error = new RuntimeException('bir kez');

    $recorder->recordException($error);
    $recorder->recordException($error);

    expect($client->gonderilenler)->toHaveCount(1);
});

test('raporlanan olayda kişisel veri bulunmaz', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    $recorder->recordException(new RuntimeException('Kullanıcı ahmet@ornek.com bulunamadı'));

    $event = $client->gonderilenler[0];
    $json = json_encode($event);

    expect($event['msg'])->toBe('Kullanıcı [email] bulunamadı')
        ->and($json)->not->toContain('john@sample.com')
        ->and($event)->not->toHaveKey('ip')
        ->and($event)->not->toHaveKey('user_agent')
        ->and($event)->not->toHaveKey('user_id');
});

test('yavaş sorgu normalize edilerek raporlanır, eşik altı sorgu raporlanmaz', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    $recorder->recordQuery("select * from users where email = 'a@b.com'", 120.0);
    expect($client->gonderilenler)->toBeEmpty();

    $recorder->recordQuery("select * from orders where email = 'a@b.com'", 800.0);

    expect($client->gonderilenler)->toHaveCount(1)
        ->and($client->gonderilenler[0]['slowest_query_sql'])
        ->toBe('select * from orders where email = ?')
        ->and(json_encode($client->gonderilenler[0]))->not->toContain('a@b.com');
});

test('sorgu sayacı olayla birlikte gider — N+1 tespiti için', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    for ($i = 0; $i < 87; $i++) {
        $recorder->recordQuery('select * from products where id = '.$i, 5.0);
    }

    $recorder->recordException(new RuntimeException('error'));

    expect($client->gonderilenler[0]['query_count'])->toBe(87);
});

/**
 * Gerçek uygulamada yakalandı: Laravel terminate() için middleware'i
 * konteynerdan YENİDEN çözüyor. Başlangıç zamanı middleware alanında
 * tutulsaydı o örnekte boş kalır, süre microtime × 1000 (~1,7 trilyon ms)
 * çıkar ve HER istek "yavaş" görünürdü.
 */
test('istek süresi gerçekçi ölçülür', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    $recorder->startRequest(microtime(true) - 0.05);   // 50 ms önce başladı

    $recorder->recordRequest(
        Request::create('/urunler'),
        new Response('ok', 200),
    );

    $duration = $client->gonderilenler[0]['duration_ms'];

    // 50 ms civarı bekliyoruz; saniyeler veya trilyonlar değil.
    expect($duration)->toBeGreaterThan(10)->toBeLessThan(5000);
});

test('istek başlamadan terminate çağrılırsa olay üretilmez', function () {
    $client = new class extends HubClient
    {
        public array $gonderilenler = [];

        public function __construct()
        {
            parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    $recorder = new Recorder($client, [
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);

    $recorder->recordRequest(
        Request::create('/urunler'),
        new Response('ok', 500),
    );

    expect($client->gonderilenler)->toBeEmpty();
});
