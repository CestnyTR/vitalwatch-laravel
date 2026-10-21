<?php

use CestnyTR\VitalWatch\Http\Middleware\MeasureRequest;
use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| Gerçek istek yaşam döngüsü: handler → reportable → terminate.
|
| Eskiden hata veren her istek İKİ kayıt açıyordu: stack'li `exception` ve
| ölçüm middleware'inin stack'siz `http_5xx`'i. Hata kaydında rota da yoktu;
| panelde hangi uçta patladığı görünmüyordu.
*/

beforeEach(function () {
    $this->gonderilenler = [];
    $list = &$this->gonderilenler;

    $this->app->instance(HubClient::class, new class($list) extends HubClient
    {
        public function __construct(public array &$list)
        {
            parent::__construct('https://x.test', 'test-project', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            if (! isset($payload['events'])) {
                $this->list[] = $payload;
            }

            return ['sent' => true, 'status' => 204];
        }
    });
    $this->app->forgetInstance(Recorder::class);

    // Testte runningInConsole() true; sağlayıcı middleware'i eklemiyor.
    $this->app->make(Kernel::class)->pushMiddleware(MeasureRequest::class);
});

test('hata veren istek tek kayıt açar, rota ve yöntemle', function () {
    Route::post('/api/urunler/{id}', fn () => throw new RuntimeException('stok servisi'));

    $this->post('/api/urunler/42')->assertStatus(500);

    expect(array_column($this->gonderilenler, 'kind'))->toBe(['exception'])
        ->and($this->gonderilenler[0]['route'])->toBe('/api/urunler/{id}')
        ->and($this->gonderilenler[0]['method'])->toBe('POST')
        ->and($this->gonderilenler[0]['exception_class'])->toBe(RuntimeException::class);
});

test('hatasız 5xx yine http_5xx olarak raporlanır', function () {
    Route::get('/maintenance', fn () => response('bakımda', 503));

    $this->get('/maintenance')->assertStatus(503);

    expect(array_column($this->gonderilenler, 'kind'))->toBe(['http_5xx']);
});

test('4xx gönderilmez', function () {
    Route::get('/none', fn () => abort(404));
    Route::get('/yasak', fn () => abort(403));

    $this->get('/none')->assertStatus(404);
    $this->get('/yasak')->assertStatus(403);

    expect($this->gonderilenler)->toBe([]);
});

/*
| Paket log olayını da dinliyor. Uygulama yakaladığı 404'ü kendisi
| günlüğe yazınca Laravel'in dontReport listesi devreye girmiyor ve hata
| hub'a gidiyordu.
*/
test('günlüğe yazılan 4xx hatası gönderilmez', function () {
    Log::warning('ürün yok', ['exception' => new ModelNotFoundException]);
    Log::warning('yasak', ['exception' => new HttpException(403)]);

    expect($this->gonderilenler)->toBe([]);
});

test('günlüğe yazılan 5xx hatası gönderilir', function () {
    Log::error('düştü', ['exception' => new HttpException(502)]);

    expect($this->gonderilenler)->toHaveCount(1);
});

/*
| Eskiden spl_object_id dizisiydi: kimlik çöp toplanınca yeniden
| kullanılıyor, aynı kimliği alan YENİ hata sessizce yutuluyordu.
*/
test('ayrı hatalar ayrı, aynı hata tek kez gönderilir', function () {
    $recorder = $this->app->make(Recorder::class);

    for ($i = 0; $i < 3; $i++) {
        // Her tur yeni nesne; önceki çöp toplanıp kimliği yeniden kullanılabilir.
        $recorder->recordException(new RuntimeException("hata {$i}"));
    }

    $same = new RuntimeException('aynı');
    $recorder->recordException($same);
    $recorder->recordException($same);

    expect($this->gonderilenler)->toHaveCount(4);
});

class VitalWatchSyncJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void {}
}

/*
| Senkron kuyruk işi isteğin içinde çalışıyor ve JobProcessing'de Recorder
| sıfırlanıyordu: isteğin başlangıç zamanı siliniyor, ne http_5xx ne
| slow_request gönderiliyordu.
*/
test('istek içindeki senkron iş http_5xx kaydını kaybettirmez', function () {
    config(['queue.default' => 'sync']);
    Route::get('/siparis', function () {
        VitalWatchSyncJob::dispatch();

        return response('bakımda', 503);
    });

    $this->get('/siparis')->assertStatus(503);

    expect(array_column($this->gonderilenler, 'kind'))->toBe(['http_5xx']);
});

test('istek içindeki senkron iş slow_request kaydını kaybettirmez', function () {
    config(['queue.default' => 'sync', 'vitalwatch.slow_request_ms' => 0]);
    Route::get('/report', function () {
        VitalWatchSyncJob::dispatch();

        return 'ok';
    });

    $this->get('/report')->assertOk();

    expect(array_column($this->gonderilenler, 'kind'))->toBe(['slow_request']);
});

/*
| Hata gönderimi eskiden yanıttan ÖNCE yapılıyordu: hub yavaşsa kullanıcı
| zaman aşımı kadar bekliyordu. Ölçülen istekte olay terminate'e bekletilir.
*/
test('istek içindeki hata yanıt gittikten sonra gönderilir', function () {
    $list = &$this->gonderilenler;
    $yanittanOnce = null;

    Route::get('/report', function () use (&$list, &$yanittanOnce) {
        report(new RuntimeException('arka plan servisi'));
        $yanittanOnce = count($list);

        return 'ok';
    });

    $this->get('/report')->assertOk();

    expect($yanittanOnce)->toBe(0)
        ->and(array_column($this->gonderilenler, 'kind'))->toBe(['exception']);
});

test('ölçülen istek dışında hata hemen gönderilir', function () {
    report(new RuntimeException('konsol'));

    expect($this->gonderilenler)->toHaveCount(1)
        // Testbench APP_ENV=testing; hub yalnızca local'i tanıyor.
        ->and($this->gonderilenler[0]['env'])->toBe('local');
});

test('terminate e ulaşmayan istekte bekleyen olay ölümcül hatayla birlikte gider', function () {
    $recorder = $this->app->make(Recorder::class);
    $recorder->startRequest(microtime(true));
    $recorder->recordException(new RuntimeException('önce'));

    expect($this->gonderilenler)->toHaveCount(0);

    $recorder->recordFatal(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x.php', 'line' => 1]);

    expect(array_column($this->gonderilenler, 'kind'))->toBe(['exception', 'fatal']);
});
