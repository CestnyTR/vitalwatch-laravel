<?php

use CestnyTR\VitalWatch\Http\Middleware\MeasureRequest;
use CestnyTR\VitalWatch\VitalWatchServiceProvider;
use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\HttpFoundation\Response;

/*
| HTTP dışı yollar: kuyruk, zamanlayıcı, uzun ömürlü işçi.
|
| `job_failed` README'de yazıyordu ama hiç gönderilmiyordu; zamanlanmış
| görevin sessiz başarısızlığı hiç görünmüyordu; yalnızca kuyruk çalıştıran
| uygulama canlılık isteği göndermiyordu.
*/

class VitalWatchBrokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        throw new RuntimeException('fatura servisi yanıt vermedi');
    }
}

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
            $this->list[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    });
    $this->app->forgetInstance(Recorder::class);
});

afterEach(function () {
    unset($_SERVER['LARAVEL_OCTANE']);
});

test('başarısız kuyruk işi job_failed olarak tek kayıt açar', function () {
    config(['queue.default' => 'sync']);

    try {
        VitalWatchBrokenJob::dispatch();
    } catch (RuntimeException $e) {
        // Laravel aynı hatayı ardından handler'a da veriyor (Worker::runJob).
        $this->app->make(ExceptionHandler::class)->report($e);
    }

    $events = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($events, 'kind'))->toBe(['job_failed'])
        ->and($events[0]['route'])->toBe(VitalWatchBrokenJob::class)
        ->and($events[0]['msg'])->toContain('fatura servisi');
});

test('başarısız zamanlanmış görev command_failed olarak gelir, PHP yolu atılır', function () {
    $task = $this->app->make(Schedule::class)->command('rapor:gonder --gunluk');
    $error = new Exception("Scheduled command [{$task->command}] failed with exit code [1].");

    event(new ScheduledTaskFailed($task, $error));
    $this->app->make(ExceptionHandler::class)->report($error);

    $events = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($events, 'kind'))->toBe(['command_failed'])
        ->and($events[0]['route'])->toBe('artisan rapor:gonder --gunluk')
        // Mesajdaki PHP yolu da atılır; yoksa parmak izi sunucuya göre bölünür.
        ->and($events[0]['msg'])->toBe('Scheduled command [artisan rapor:gonder --gunluk] failed with exit code [1].')
        ->and($events[0]['msg'])->not->toContain(PHP_BINARY);
});

// Uzun sınıf adı jeton sanılıyordu: `App\Jobs\[token]`, farklı işler tek satır.
test('iş sınıfı adı maskelenmez', function () {
    $this->app->make(Recorder::class)->recordJobFailed(
        'App\Jobs\SendMonthlyInvoiceReminderEmails',
        new RuntimeException('smtp kapalı'),
    );

    expect($this->gonderilenler[0]['route'])->toBe('App\Jobs\SendMonthlyInvoiceReminderEmails');
});

test('kuyruk döngüsü canlılık gönderir', function () {
    event(new Looping('sync', 'default'));

    expect(array_filter($this->gonderilenler, fn ($o) => isset($o['events'])))->toHaveCount(1);
});

/*
| Octane'da Recorder yüzlerce istek yaşıyor. Başlangıç `??=` ile ilk isteğe
| yapışıyordu: ilk istekten sonra her istek "yavaş" görünürdü.
*/
test('istek bitince durum sıfırlanır, sonraki istek temiz başlar', function () {
    $recorder = $this->app->make(Recorder::class);
    $request = Request::create('/report');

    $recorder->startRequest(microtime(true) - 5);
    $recorder->recordRequest($request, new Response('ok'));

    $recorder->startRequest(microtime(true));
    $recorder->recordRequest($request, new Response('ok'));

    $yavaslar = array_filter($this->gonderilenler, fn ($o) => ($o['kind'] ?? null) === 'slow_request');

    expect($yavaslar)->toHaveCount(1);
});

test('Octane işçisi tanınır', function () {
    expect(Recorder::octane())->toBeFalse();

    $_SERVER['LARAVEL_OCTANE'] = 1;

    expect(Recorder::octane())->toBeTrue();
});

/*
| Octane işçileri CLI altında çalışıyor. Sağlayıcı yalnızca
| runningInConsole()'a bakınca ölçüm hiç kurulmuyordu.
*/
test('Octane altında istek ölçümü kurulur', function () {
    $kernel = $this->app->make(Kernel::class);
    $provider = new VitalWatchServiceProvider($this->app);

    $provider->boot();
    expect($kernel->hasMiddleware(MeasureRequest::class))->toBeFalse();

    $_SERVER['LARAVEL_OCTANE'] = 1;
    $provider->boot();

    expect($kernel->hasMiddleware(MeasureRequest::class))->toBeTrue();
});

/*
| Octane her isteği uygulamanın bir kopyasında işliyor. Recorder açılışta
| çözülmezse her kopya kendi Recorder'ını açıyor, kancalar ana
| uygulamadakine yazıyordu: istek kayıtlarında sorgu sayısı hep 0.
*/
test('Recorder açılışta çözülür, Octane kopyaları aynı örneği paylaşır', function () {
    $this->app->forgetInstance(Recorder::class);

    (new VitalWatchServiceProvider($this->app))->boot();

    $copy = clone $this->app;

    expect($copy->make(Recorder::class))->toBe($this->app->make(Recorder::class));
});

/*
| Zaman aşımına uğrayan iş.
|
| Deneme hakkı bitmişse Laravel işi başarısız sayıyor ve JobFailed geliyor.
| Hak kaldığında ise işçi süreci sinyalle öldürülüyor ve eskiden hiçbir olay
| gelmiyordu: kuyruk birikiyor, panelde tek iz kalmıyordu.
*/
test('zaman aşımına uğrayan iş job_failed olarak gelir', function () {
    $is = new class
    {
        public function resolveName(): string
        {
            return 'App\Jobs\RaporHazirla';
        }

        public function timeout(): int
        {
            return 60;
        }
    };

    event(new JobTimedOut('redis', $is));

    $events = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($events, 'kind'))->toBe(['job_failed'])
        ->and($events[0]['msg'])->toBe('Queue job timed out: App\Jobs\RaporHazirla (60 s)')
        ->and($events[0]['route'])->toBe('App\Jobs\RaporHazirla')
        ->and($events[0]['exception_class'])->toBe('JobTimedOut');
});

/*
| Laravel önce işi başarısız işaretliyor (JobFailed), sonra JobTimedOut
| yayıyor (Worker::registerTimeoutHandler). İkisi de raporlansaydı tek arıza
| panelde iki satır olurdu.
*/
test('hakkı biten işte zaman aşımı ikinci kayıt açmaz', function () {
    $recorder = $this->app->make(Recorder::class);

    $recorder->recordJobFailed('App\Jobs\RaporHazirla', new RuntimeException('zaman aşımı'));
    $recorder->recordJobTimedOut('App\Jobs\RaporHazirla', 60);

    $events = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect($events)->toHaveCount(1)
        ->and($events[0]['exception_class'])->toBe(RuntimeException::class);
});

/* Sonraki iş temiz başlamalı: işaret JobProcessing'de sıfırlanıyor. */
test('sonraki işin zaman aşımı raporlanır', function () {
    $recorder = $this->app->make(Recorder::class);

    $recorder->recordJobFailed('App\Jobs\Birinci', new RuntimeException('patladı'));
    $recorder->reset();
    $recorder->recordJobTimedOut('App\Jobs\Ikinci', 30);

    $events = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($events, 'route'))->toBe(['App\Jobs\Birinci', 'App\Jobs\Ikinci']);
});
