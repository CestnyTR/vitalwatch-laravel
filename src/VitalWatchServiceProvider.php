<?php

namespace CestnyTR\VitalWatch;

use CestnyTR\VitalWatch\Console\StatusCommand;
use CestnyTR\VitalWatch\Http\Middleware\MeasureRequest;
use CestnyTR\VitalWatch\Support\Environment;
use CestnyTR\VitalWatch\Support\Release;
use CestnyTR\VitalWatch\Transport\HubClient;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Registers VitalWatch: config, the `vitalwatch:status` command and the
 * exception, query, request, queue and scheduler hooks. Loaded through
 * package discovery; installs nothing when `VITALWATCH_ENABLED=false`.
 */
class VitalWatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vitalwatch.php', 'vitalwatch');

        $this->app->singleton(HubClient::class, fn ($app) => new HubClient(
            url: config('vitalwatch.url'),
            key: config('vitalwatch.key'),
            secret: config('vitalwatch.secret'),
            timeout: config('vitalwatch.timeout'),
        ));

        $this->app->singleton(Recorder::class, fn ($app) => new Recorder(
            client: $app->make(HubClient::class),
            config: [
                // Canlılık önbelleği proje bazlı anahtarlanıyor; aynı cache
                // deposunu paylaşan iki uygulama birbirinin nabzını
                // bastırmasın.
                'key' => config('vitalwatch.key'),
                'env' => Environment::normalize(config('vitalwatch.env') ?: $app->environment()),
                // VITALWATCH_RELEASE boşsa CI değişkeni ya da `.git`'ten okunur
                // (bkz. Release). Her açılışta okunur, önbelleklenmez.
                'release' => Release::detect($app->basePath(), config('vitalwatch.release'))['value'],
                'slow_request_ms' => config('vitalwatch.slow_request_ms'),
                'slow_query_ms' => config('vitalwatch.slow_query_ms'),
                'capture_user_id' => config('vitalwatch.capture_user_id'),
                'ignore' => config('vitalwatch.ignore', []),
            ],
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vitalwatch.php' => config_path('vitalwatch.php'),
        ], 'vitalwatch');

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class]);
        }

        // VITALWATCH_ENABLED=false iken hiçbir kanca kurulmaz — sıfır ek yük.
        if (! config('vitalwatch.enabled')) {
            return;
        }

        // Yapılandırma eksikse de kanca kurulmaz: her istekte boşuna
        // ölçüm yapmanın anlamı yok.
        if (! $this->app->make(HubClient::class)->configured()) {
            return;
        }

        /*
        | Recorder şimdi çözülür: Octane her isteği uygulamanın bir
        | kopyasında (sandbox) işliyor. Tekil burada oluşmazsa her kopya
        | kendi Recorder'ını açıyordu; kancalar ana uygulamadakine, ölçüm
        | middleware'i kopyadakine yazıyor, istek kayıtlarında sorgu sayısı
        | hep 0 görünüyordu.
        */
        $this->app->make(Recorder::class);

        $this->hookQueries();
        $this->hookExceptions();
        $this->hookRequests();
        $this->hookQueue();
        $this->hookSchedule();
        $this->hookFatals();
    }

    /**
     * Kuyruk: başarısız iş, iş başına durum sıfırlama, canlılık.
     *
     * **job_failed** README'de yıllardır yazıyordu ama hiç gönderilmiyordu.
     * Deneme hakkı biten iş `JobFailed` olayıyla gelir; Laravel aynı hatayı
     * ardından handler'a da raporluyor (Worker::runJob) — önce burada
     * kaydedildiği için Recorder ikincisini eler, panelde tek satır olur.
     * Ara denemeler `exception` olarak gelmeye devam eder.
     *
     * **Canlılık:** yalnızca kuyruk ya da zamanlayıcı çalıştıran uygulamada
     * hiç HTTP isteği olmuyor ve canlılık isteği hiç gitmiyordu; kurulum "sessiz"
     * görünüyordu. Looping boştayken de tetiklenir; Recorder önbelleğe
     * dakikada en fazla bir kez bakar.
     */
    private function hookQueue(): void
    {
        try {
            $events = $this->app['events'];

            /*
            | Senkron iş (sync, deferred) HTTP isteğinin İÇİNDE çalışır:
            | sıfırlama isteğin başlangıç zamanını silip slow_request ve
            | http_5xx'i kaybettiriyor, canlılık da yanıttan önce ağ
            | isteği atıyordu. Bu işlerde ikisi de atlanır; başarısızlık
            | kaydı kalır (isteği de işaretler).
            */
            $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->syncJob($e->job)
                ? null
                : $this->safely(fn (Recorder $r) => $r->reset()));

            $events->listen(JobFailed::class, fn (JobFailed $e) => $this->safely(
                fn (Recorder $r) => $r->recordJobFailed($this->jobName($e->job), $e->exception),
            ));

            /*
            | Zaman aşımı: deneme hakkı bitmişse JobFailed ÖNCE geliyor
            | (Worker::registerTimeoutHandler) ve kayıt orada açılıyor. Bu
            | dinleyici yalnızca "iş yeniden denenecek ama süreç öldü"
            | durumunu yakalıyor — eskiden hiçbir iz bırakmıyordu.
            */
            $events->listen(JobTimedOut::class, fn (JobTimedOut $e) => $this->safely(
                fn (Recorder $r) => $r->recordJobTimedOut($this->jobName($e->job), $this->jobTimeout($e->job)),
            ));

            $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->syncJob($e->job)
                ? null
                : $this->safely(fn (Recorder $r) => $r->heartbeatIfDue()));
            $events->listen(Looping::class, fn () => $this->safely(fn (Recorder $r) => $r->heartbeatIfDue()));
        } catch (Throwable) {
            // Sessiz.
        }
    }

    /**
     * Zamanlanmış görevler: başarısızlık `command_failed`, her tur canlılık.
     *
     * Cron'la çalışan görev sessizce başarısız olabiliyor — kimse ekrana
     * bakmıyor. Laravel sıfır olmayan çıkışı da istisnaya çeviriyor ve önce
     * bu olayı, sonra handler'ı çağırıyor; kayıt burada `command_failed`
     * türüyle açılır, handler'ınki elenir.
     */
    private function hookSchedule(): void
    {
        try {
            $events = $this->app['events'];

            $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->safely(
                fn (Recorder $r) => $r->recordScheduledTaskFailed(
                    $e->task->description ?: (string) $e->task->command,
                    $e->exception,
                ),
            ));

            $events->listen(ScheduledTaskFinished::class, fn () => $this->safely(fn (Recorder $r) => $r->heartbeatIfDue()));
        } catch (Throwable) {
            // Sessiz.
        }
    }

    /** İstek sürecinde çalışan iş: sync ve deferred bağlantıları SyncJob üretir. */
    private function syncJob(mixed $job): bool
    {
        try {
            return $job instanceof SyncJob
                || in_array($job->getConnectionName(), ['sync', 'deferred'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** İşin kendi zaman aşımı; tanımsızsa null (worker seçeneği geçerli). */
    private function jobTimeout(mixed $job): ?int
    {
        try {
            $duration = $job->timeout();

            return is_numeric($duration) ? (int) $duration : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function jobName(mixed $job): string
    {
        try {
            return (string) $job->resolveName();
        } catch (Throwable) {
            return 'unknown-job';
        }
    }

    /** Kanca gövdesi: Recorder'a ulaşır, hiçbir koşulda fırlatmaz. */
    private function safely(callable $callback): void
    {
        try {
            $callback($this->app->make(Recorder::class));
        } catch (Throwable) {
            // Kendi hatasını raporlamaz — sonsuz döngü riski.
        }
    }

    /**
     * Ölümcül hatalar — istisna mekanizmasından geçmeyenler.
     *
     * Bellek tükenmesi, zaman aşımı ve derleme hataları PHP'de exception
     * üretmez: süreç ölür, `hookExceptions()` hiç çalışmaz. Yani siteyi
     * **gerçekten düşüren** hata sınıfı bugüne kadar panele hiç düşmedi.
     * Uptime probu 500'ü görüyordu ama sebebini kimse bilmiyordu.
     */
    private function hookFatals(): void
    {
        /*
        | Bellek tamponu. Bellek tükendiğinde raporlama kodunun kendisi de
        | yer bulamaz — kanca yazılır ama tam ihtiyaç anında sessizce
        | çalışmaz. Açılışta ayrılan bu blok, shutdown anında serbest
        | bırakılıp gönderime nefes aldırıyor.
        */
        $reserve = str_repeat(' ', 256 * 1024);

        register_shutdown_function(function () use (&$reserve) {
            $reserve = null;

            $error = error_get_last();

            if ($error === null) {
                return;
            }

            /*
            | Yalnızca ölümcül türler. Uyarı ve bildirim (E_WARNING,
            | E_NOTICE, E_DEPRECATED) buraya girmez: her istekte onlarca
            | üretilebilir ve paneli tamamen boğardı.
            */
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

            if (($error['type'] & $fatal) === 0) {
                return;
            }

            try {
                $this->app->make(Recorder::class)->recordFatal($error);
            } catch (Throwable) {
                // Ölmekte olan süreçte bile uygulamayı etkilemez.
            }
        });
    }

    /** Yavaş sorgu ve sorgu sayacı. */
    private function hookQueries(): void
    {
        try {
            DB::listen(function (QueryExecuted $query) {
                try {
                    $this->app->make(Recorder::class)->recordQuery($query->sql, $query->time);
                } catch (Throwable) {
                    // Sessiz.
                }
            });
        } catch (Throwable) {
            // Veritabanı yapılandırılmamış olabilir; izleme buna takılmaz.
        }
    }

    /**
     * Exception kancası — iki yoldan.
     *
     * **1. MessageLogged olayı (birincil).** Laravel'in handler'ı raporlanan
     * her istisnayı `['exception' => $e]` bağlamıyla loglar. Bu olay handler
     * sınıfından bağımsızdır ve her zaman tetiklenir.
     *
     * **2. reportable (ikincil).** Standart kurulumlarda çalışır.
     *
     * Neden ikisi birden: Collision gibi paketler konsolda handler'ı kendi
     * sarmalayıcısıyla değiştiriyor ve boot sırasında kaydedilen reportable
     * geri çağrısı başka bir örnekte kalabiliyor — gerçek bir kurulumda tam
     * olarak bu yaşandı, hiçbir istisna raporlanmadı. Çift kayıt sorun değil:
     * Recorder aynı istisnayı iki kez göndermez (WeakMap ile eler).
     *
     * `reportable` **`false` DÖNDÜRMEZ** — döndürseydi Laravel'in kendi
     * loglaması durur ve paket uygulamanın davranışını değiştirmiş olurdu.
     */
    private function hookExceptions(): void
    {
        try {
            $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) {
                $e = $event->context['exception'] ?? null;

                if ($e instanceof Throwable) {
                    $this->record($e);
                }
            });
        } catch (Throwable) {
            // Sessiz.
        }

        // Sağlayıcı sırası önemli: tüm paketler yüklendikten sonra çözülür.
        $this->app->booted(function () {
            try {
                $handler = $this->app->make(ExceptionHandler::class);

                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(fn (Throwable $e) => $this->record($e));
                }
            } catch (Throwable) {
                // Sessiz.
            }
        });
    }

    private function record(Throwable $e): void
    {
        try {
            $this->app->make(Recorder::class)->recordException($e);
        } catch (Throwable) {
            // Kendi hatasını raporlamaz — sonsuz döngü riski.
        }
    }

    /** Yavaş istek ve 5xx ölçümü. */
    private function hookRequests(): void
    {
        try {
            /*
            | Octane işçileri CLI altında çalışıyor; yalnızca
            | runningInConsole()'a bakılınca Octane'da istek ölçümü hiç
            | kurulmuyordu — yavaş istek, 5xx ve canlılık gelmiyordu.
            */
            if (! $this->app->runningInConsole() || Recorder::octane()) {
                $this->app->make(Kernel::class)->pushMiddleware(MeasureRequest::class);
            }
        } catch (Throwable) {
            // Sessiz.
        }
    }
}
