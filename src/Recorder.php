<?php

namespace CestnyTR\VitalWatch;

use CestnyTR\VitalWatch\Support\Scrubber;
use CestnyTR\VitalWatch\Transport\HubClient;
use Composer\InstalledVersions;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * İstek boyunca ölçüm biriktirir ve olayları hub'a gönderir.
 *
 * Tek istek yaşam döngüsü boyunca tekil (singleton) çalışır: sorgu sayacı ve
 * en yavaş sorgu burada tutulur, istek bitiminde raporla birlikte gönderilir.
 */
class Recorder
{
    private int $queryCount = 0;

    private ?float $slowestQueryMs = null;

    private ?string $slowestQuerySql = null;

    /**
     * Aynı exception iki kez raporlanmasın.
     *
     * WeakMap: eskiden `spl_object_id` dizisiydi. Nesne kimliği çöp
     * toplanınca yeniden kullanılıyor ve dizi hiç temizlenmiyordu — uzun
     * ömürlü süreçte (kuyruk işçisi, Octane) aynı kimliği alan YENİ bir
     * hata "zaten raporlandı" diye sessizce yutuluyor, dizi de büyüyordu.
     *
     * @var \WeakMap<Throwable, true>
     */
    private \WeakMap $reported;

    /*
    | Hata raporlandıysa istek işaretlenir; istek bitiminde aynı istek için
    | ayrıca stack'siz bir "HTTP 500" açılmaz. Node SDK'daki işaretin
    | karşılığı (vitalwatch.reported).
    */
    private const REPORTED_ATTRIBUTE = 'vitalwatch.reported';

    /**
     * İstek başlangıcı. Middleware'de DEĞİL burada tutuluyor: Laravel
     * terminate() için middleware'i konteynerdan yeniden çözüyor ve yeni
     * örnekte alan boş kalıyordu. Sonuç süre olarak microtime × 1000
     * (~1,7 trilyon ms) çıkıyor, her istek "yavaş" görünüyordu.
     *
     * Recorder istek boyunca singleton; doğru yer burası.
     */
    private ?float $startedAt = null;

    public function __construct(
        private readonly HubClient $client,
        private readonly array $config,
    ) {
        $this->reported = new \WeakMap;
    }

    /**
     * İstek bitimini bekleyen olaylar (gövdesi hazır).
     *
     * Hata ve yavaş sorgu gönderimi eskiden anında yapılıyordu: `report()`
     * yanıttan ÖNCE çalışıyor, hub yavaşsa kullanıcı zaman aşımı kadar
     * (en çok 10 sn) bekliyordu. Ölçülen bir HTTP isteğinde olay burada
     * bekler, terminate'te — yanıt gittikten sonra — gönderilir.
     *
     * @var list<array<string, mixed>>
     */
    private array $pending = [];

    /*
    | Bu iş için kayıt açıldı mı. Zaman aşımında Laravel önce işi başarısız
    | işaretliyor (JobFailed), sonra JobTimedOut yayıyor; ikisi de
    | raporlansaydı tek arıza panelde iki satır olurdu.
    */
    private bool $jobReported = false;

    /** terminate aşaması: artık ertelemek yok, doğrudan gönder. */
    private bool $terminating = false;

    private bool $shutdownRegistered = false;

    /** Canlılık önbelleğine en son bakılan an; kuyruk döngüsünü seyreltir. */
    private ?float $heartbeatCheckedAt = null;

    /** Middleware'in handle aşamasında çağrılır. */
    public function startRequest(float $at): void
    {
        // İlk çağrı kazanır: alt istekler (Route::dispatch) başlangıcı ezmesin.
        $this->startedAt ??= $at;
    }

    /**
     * İstek/iş başına durumu sıfırlar.
     *
     * İstek bitiminde (terminate) ve kuyruk işi başlarken (JobProcessing)
     * çağrılır. FPM'de her süreç tek istek görür ve bu hiç gerekmiyordu.
     * Octane ve kuyruk işçisinde Recorder yüzlerce istek/iş boyunca yaşıyor: başlangıç
     * zamanı ilk isteğe yapışıyor (`??=`), sorgu sayacı ve en yavaş sorgu
     * işler arasında birikiyordu.
     */
    public function reset(): void
    {
        $this->jobReported = false;
        $this->startedAt = null;
        $this->queryCount = 0;
        $this->slowestQueryMs = null;
        $this->slowestQuerySql = null;
    }

    /** Octane işçisi mi. Octane sunucuyu başlatırken bu değişkeni koyuyor. */
    public static function octane(): bool
    {
        return (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE'));
    }

    /** DB::listen kancasından çağrılır. */
    public function recordQuery(string $sql, float $timeMs): void
    {
        $this->queryCount++;

        if ($this->slowestQueryMs === null || $timeMs > $this->slowestQueryMs) {
            $this->slowestQueryMs = $timeMs;
            $this->slowestQuerySql = $sql;
        }

        if ($timeMs >= $this->config['slow_query_ms']) {
            $this->send([
                'kind' => 'slow_query',
                // Normalize edilmiş SQL mesaj olarak kullanılır: aynı sorgu
                // farklı parametrelerle çalıştığında tek grupta toplanır.
                'msg' => Scrubber::sql($sql),
                'slowest_query_sql' => Scrubber::sql($sql),
                'slowest_query_ms' => (int) round($timeMs),
            ]);
        }
    }

    /**
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function recordException(Throwable $e): array
    {
        if ($this->ignored($e) || $this->clientError($e)) {
            return ['sent' => false, 'error' => 'ignored'];
        }

        if ($this->alreadyReported($e)) {
            return ['sent' => false, 'error' => 'already-reported'];
        }

        $request = $this->currentRequest();
        $request?->attributes->set(self::REPORTED_ATTRIBUTE, true);

        return $this->send([
            'kind' => 'exception',
            // QueryException mesajı SQL'i bağlanmış değerlerle taşır; oturum
            // kimliği, e-posta, kart numarası oradan sızabilir.
            'msg' => Scrubber::message($e->getMessage(), $this->containsSql($e)),
            'exception_class' => $e::class,
            'stack' => Scrubber::stack($e->getTraceAsString()),
            'file' => Scrubber::path($e->getFile()),
            'line' => $e->getLine(),
            /*
            | Rota ve yöntem eskiden hiç gönderilmiyordu: panelde hatanın
            | hangi uçta patladığı görünmüyor, "Yol" `/` kalıyordu. Desen
            | gönderilir (`/api/urunler/{id}`), gerçek id değil.
            */
            'route' => $request ? $this->routePath($request) : null,
            'method' => $request?->getMethod(),
        ]);
    }

    /**
     * Deneme hakkı biten kuyruk işi.
     *
     * Rota yerine işin sınıfı gönderilir: panelde hangi işin düştüğü "Yol"
     * sütununda görünür. 4xx eleme uygulanmaz — işte ModelNotFound gibi bir
     * hata çağıranın değil, işin kendi arızasıdır.
     *
     * Sınıf adı maskelenmez: kişisel veri değil, ama 24+ karakterlik adlar
     * (`SendMonthlyInvoiceReminderEmails`) jeton sanılıp `[token]` oluyor,
     * farklı işler panelde tek satırda birleşiyordu.
     *
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function recordJobFailed(string $job, Throwable $e): array
    {
        $this->jobReported = true;

        return $this->recordThrowable('job_failed', $e, mb_substr($job, 0, 300));
    }

    /**
     * Zaman aşımına uğrayan kuyruk işi.
     *
     * Deneme hakkı bitmişse Laravel işi zaten başarısız sayıyor ve
     * `JobFailed` geliyor; kayıt orada açılır, burası atlanır. Hak
     * kaldığında ise iş yeniden denenecek diye HİÇBİR olay gelmiyordu:
     * işçi süreci sinyalle öldürülüyor ve panelde tek iz kalmıyordu. Oysa
     * zaman aşımına uğrayan iş, tekrar denenip başarılı olsa bile arıza
     * işareti — kuyruk birikiyor ve süreç her turda ölüyor.
     *
     * İstisna yok (süreç sinyalle ölüyor), bu yüzden stack de yok.
     *
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function recordJobTimedOut(string $job, ?int $seconds = null): array
    {
        if ($this->jobReported) {
            return ['sent' => false, 'error' => 'already-reported'];
        }

        $this->jobReported = true;
        $duration = $seconds !== null ? " ({$seconds} sn)" : '';

        return $this->send([
            'kind' => 'job_failed',
            // İş adı mesajda: parmak izi yolu içermiyor, iki ayrı işin zaman
            // aşımı aynı satırda toplanmamalı.
            'msg' => Scrubber::text("Kuyruk işi zaman aşımına uğradı: {$job}{$duration}", 500),
            'exception_class' => 'JobTimedOut',
            'route' => mb_substr($job, 0, 300),
        ]);
    }

    /**
     * Başarısız zamanlanmış görev. Laravel sıfır olmayan çıkışı da istisnaya
     * çeviriyor, yani istisna her zaman var.
     *
     * Görev adı `'/usr/bin/php8.3' 'artisan' rapor:gonder` biçiminde gelir;
     * PHP yolu atılır — sunucudan sunucuya değişip aynı görevi iki satıra
     * bölmesin. Görev adı maskelenir: argümanlar parola, jeton taşıyabilir.
     *
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function recordScheduledTaskFailed(string $task, Throwable $e): array
    {
        $name = trim(self::stripPhpBinary($task));

        return $this->recordThrowable(
            'command_failed',
            $e,
            Scrubber::text($name, 300) ?? 'scheduled-task',
            // Laravel'in mesajı komutu PHP yoluyla taşıyor:
            // `Scheduled command ['/usr/bin/php8.3' 'artisan' x] failed ...`.
            // Yol mesajda kalırsa parmak izi yine sunucuya göre bölünür.
            self::stripPhpBinary(...),
        );
    }

    /** `'/usr/bin/php8.3' 'artisan' x` → `artisan x`. */
    private static function stripPhpBinary(string $value): string
    {
        return (string) (preg_replace("/(?:'[^']*'|\"[^\"]*\")\s+(?:'artisan'|\"artisan\")/", 'artisan', $value) ?? $value);
    }

    /**
     * @return array{sent: bool, status?: int, error?: string}
     */
    private function recordThrowable(string $kind, Throwable $e, string $route, ?\Closure $message = null): array
    {
        if ($this->ignored($e)) {
            return ['sent' => false, 'error' => 'ignored'];
        }

        if ($this->alreadyReported($e)) {
            return ['sent' => false, 'error' => 'already-reported'];
        }

        // Senkron kuyrukta iş HTTP isteği içinde düşer; istek de işaretlensin.
        $this->currentRequest()?->attributes->set(self::REPORTED_ATTRIBUTE, true);

        return $this->send([
            'kind' => $kind,
            'msg' => Scrubber::message(
                $message ? $message($e->getMessage()) : $e->getMessage(),
                $this->containsSql($e),
            ),
            'exception_class' => $e::class,
            'stack' => Scrubber::stack($e->getTraceAsString()),
            'file' => Scrubber::path($e->getFile()),
            'line' => $e->getLine(),
            'route' => $route,
        ]);
    }

    /**
     * İstek bitiminde çağrılır (terminate). Kullanıcı yanıtı çoktan
     * gönderilmiştir; burada harcanan süre kimseyi bekletmez.
     */
    public function recordRequest(Request $request, Response $response): void
    {
        $this->terminating = true;

        try {
            $this->flushPending();
            $this->measureRequest($request, $response);
        } finally {
            // Octane'da sonraki istek temiz başlasın.
            $this->reset();
            $this->terminating = false;
        }
    }

    /** Bekleyen olayları gönderir. Hiçbir koşulda fırlatmaz. */
    public function flushPending(): void
    {
        while ($this->pending !== []) {
            $payload = array_shift($this->pending);

            try {
                $this->client->send($payload);
            } catch (Throwable) {
                // Sessiz; sıradaki olay yine denenir.
            }
        }
    }

    private function measureRequest(Request $request, Response $response): void
    {
        if ($this->startedAt === null) {
            return;
        }

        $durationMs = (microtime(true) - $this->startedAt) * 1000;
        $status = $response->getStatusCode();
        $slow = $durationMs >= $this->config['slow_request_ms'];

        if (! $slow && $status < 500) {
            return;
        }

        // Hata stack'iyle raporlandı; "HTTP 500" aynı arızanın tekrarı olur.
        if ($status >= 500 && $request->attributes->get(self::REPORTED_ATTRIBUTE)) {
            return;
        }

        $this->send([
            'kind' => $status >= 500 ? 'http_5xx' : 'slow_request',
            'msg' => $this->routePattern($request).' — '.($status >= 500
                ? "HTTP {$status}"
                : round($durationMs).' ms'),
            'route' => $this->routePattern($request),
            'method' => $request->getMethod(),
            'status' => $status,
            'controller' => $this->controller($request),
            'duration_ms' => (int) round($durationMs),
            'memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
        ]);
    }

    /**
     * Kurulu paket sürümü.
     *
     * Composer 2'nin çalışma zamanı API'sinden okunuyor; ayrıca elle
     * güncellenen bir sabit tutmak, sürüm atlandığında sessizce yanlış bilgi
     * vermek demekti.
     */
    public static function version(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                return (string) InstalledVersions::getPrettyVersion('cestnytr/vitalwatch-laravel');
            }
        } catch (Throwable) {
            // Sürüm okunamadıysa raporlama durmaz.
        }

        return 'isUnknown';
    }

    /**
     * Canlılık aralığı — hub'ın sessizlik eşiğinin (24 saat) üçte biri.
     *
     * Aralık eşiğe eşit olsaydı tek bir kaçırılan istek — deploy, yeniden
     * başlatma, kısa bir ağ kesintisi — kurulumu bozuk gösterirdi.
     */
    private const HEARTBEAT_HOURS = 8;

    /**
     * Ölümcül hata — istisna mekanizmasından geçmeyen ölüm.
     *
     * `error_get_last()` çıktısı beklenir. Stack trace yoktur: süreç zaten
     * ölmüş, `debug_backtrace()` çağrılabilecek bir bağlam kalmamıştır.
     * Elde olan dosya, satır ve mesajdır — ki bellek hatasında mesaj
     * genellikle limiti ve ayrılmaya çalışılan boyutu taşır, yani en
     * değerli bilgi zaten oradadır.
     *
     * @param  array{type: int, message: string, file: string, line: int}  $error
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function recordFatal(array $error): array
    {
        // Süreç ölüyor: bekleyenler ve bu olay hemen gider, terminate gelmeyecek.
        $this->terminating = true;
        $this->flushPending();

        return $this->send([
            'kind' => 'fatal',
            /*
            | Mesaj temizlikten geçer: bellek hatası dosya yollarını,
            | zaman aşımı ise çalışan sorguyu taşıyabiliyor.
            */
            'msg' => Scrubber::message($this->fatalLabel($error['type']).': '.$error['message'], true),
            'file' => Scrubber::path($error['file'] ?? null),
            'line' => $error['line'] ?? null,
            // Ölüm anındaki bellek: limite mi çarpıldı, yoksa başka bir
            // sebeple mi ölündü — ayrımı bu sayı veriyor.
            'memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
        ]);
    }

    /**
     * Ölümcül hata türünün okunur adı.
     *
     * Ham sayı (`1`, `64`) panelde hiçbir şey ifade etmiyor; hangi sınıf
     * hatanın olduğu mesajın başında yazılı olmalı.
     */
    private function fatalLabel(int $type): string
    {
        return match ($type) {
            E_ERROR => 'Ölümcül hata',
            E_PARSE => 'Sözdizimi hatası',
            E_CORE_ERROR => 'Çekirdek hatası',
            E_COMPILE_ERROR => 'Derleme hatası',
            E_USER_ERROR => 'Uygulama hatası',
            default => 'Ölümcül hata',
        };
    }

    /**
     * "Buradayım" — olay taşımayan canlılık isteği.
     *
     * Hub bir kurulumun çalıştığını yalnızca hata gelmesinden anlıyordu ve
     * sonuç ters dönüyordu: hatasız çalışan uygulama "kurulum bozuk"
     * görünüyordu. Artık kanıt isteğin kendisi.
     *
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function heartbeat(): array
    {
        return $this->send(['events' => []]);
    }

    /**
     * Süresi geldiyse canlılık gönderir.
     *
     * **Zamanlayıcıya bağlanmadı, isteğe bağlandı.** Gerekçe: paket onlarca
     * projeye kuruluyor ve hepsinde çalışan bir cron olduğu varsayılamaz.
     * Zamanlayıcısı olmayan bir projede canlılık isteği hiç gitmez ve kurulum sessizce
     * "bozuk" görünür — düzeltmeye çalıştığımız hatanın aynısı.
     *
     * İsteğe bağlamanın ikinci faydası: hub'ın kendi uptime probu da bir
     * istektir. Hiç ziyaretçisi olmayan bir site bile prob sayesinde nabzını
     * atmaya devam eder.
     */
    public function heartbeatIfDue(): void
    {
        /*
        | Kuyruk döngüsü (Looping) boştayken saniyeler içinde tekrar
        | tetikleniyor. Önbelleğe dakikada en fazla bir kez bakılır; aradaki
        | çağrılar bellek içinde döner.
        */
        // now(): testte zaman ileri alınabilsin.
        $now = (float) now()->getTimestamp();

        if ($this->heartbeatCheckedAt !== null && $now - $this->heartbeatCheckedAt < 60) {
            return;
        }

        $this->heartbeatCheckedAt = $now;

        $key = 'vitalwatch:heartbeat:'.($this->config['key'] ?? 'unknown');

        // add(): yalnızca anahtar yoksa yazar ve true döner — yarış koşulunda
        // iki eşzamanlı istek iki canlılık isteği göndermesin.
        if (! Cache::add($key, true, now()->addHours(self::HEARTBEAT_HOURS))) {
            return;
        }

        $this->heartbeat();
    }

    /**
     * Ortak alanları ekleyip gönderir.
     *
     * @param  array<string, mixed>  $event
     * @return array{sent: bool, status?: int, error?: string}
     */
    private function send(array $event): array
    {
        try {
            $payload = array_filter([
                ...$event,
                'env' => $this->config['env'],
                'release' => $this->config['release'],
                'query_count' => $this->queryCount,
                'slowest_query_ms' => $event['slowest_query_ms']
                    ?? ($this->slowestQueryMs !== null ? (int) round($this->slowestQueryMs) : null),
                'slowest_query_sql' => $event['slowest_query_sql']
                    ?? Scrubber::sql($this->slowestQuerySql),
                'php_version' => PHP_VERSION,
                /*
                | Hangi projenin eski SDK sürümünde kaldığı, ancak olayla
                | birlikte gelirse görülebiliyor. Onlarca kurulumda tek tek
                | sunucuya girmeden bilmenin başka yolu yok.
                */
                'sdk_version' => self::version(),
                'framework_version' => app()->version(),
                'user_id' => $this->userId(),
            ], fn ($v) => $v !== null);

            if ($this->shouldDefer($payload)) {
                $this->pending[] = $payload;

                return ['sent' => false, 'error' => 'ertelendi'];
            }

            return $this->client->send($payload);
        } catch (Throwable $e) {
            // Kendi hatasını raporlamaz — sonsuz döngü riski. Sonuç yalnızca
            // teşhis komutu için üretiliyor.
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ölçülen bir HTTP isteğinin içindeysek olay terminate'e bekletilir.
     *
     * `startedAt` yalnızca ölçüm ara katmanı isteği başlattığında dolu;
     * terminate onu mutlaka boşaltıyor. Konsol, kuyruk ve teşhis komutu
     * bu koşulu hiç sağlamaz — oradaki davranış değişmez. Canlılık isteği
     * (`events`) ertelenmez: terminate'ten sonra zaten çağrılıyor.
     *
     * Süreç terminate'e ulaşmadan biterse (exit, ölümcül hata) bekleyenler
     * kapanışta gönderilir.
     */
    private function shouldDefer(array $payload): bool
    {
        if ($this->terminating || $this->startedAt === null || isset($payload['events'])) {
            return false;
        }

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn () => $this->flushPending());
        }

        return true;
    }

    /**
     * Route deseni gönderilir, gerçek id değil: `/api/products/42` yerine
     * `GET /api/products/{id}`. Böylece hem gruplama çalışır hem yolda
     * kişisel veri taşınmaz.
     */
    private function routePattern(Request $request): string
    {
        return $request->getMethod().' '.$this->routePath($request);
    }

    /** Yöntemsiz desen: `/api/products/{id}`. */
    private function routePath(Request $request): string
    {
        $uri = $request->route()?->uri();

        return '/'.ltrim($uri ?? Scrubber::path($request->getPathInfo()) ?? '', '/');
    }

    /**
     * Hata anındaki HTTP isteği; konsolda ya da istek dışında null.
     *
     * `runningInConsole()` tek başına yetmiyor: testlerde ve Octane'da CLI
     * altında gerçek istekler işleniyor. Eşleşmiş rota, web SAPI'si ya da
     * Octane işçisi gerçek bir istek demek; konsolun varsayılan boş isteği
     * bunların hiçbirini taşımaz. Octane'da rotadan önce (global
     * middleware'de) düşen hata da böylece isteği işaretler.
     */
    private function currentRequest(): ?Request
    {
        try {
            if (! app()->bound('request')) {
                return null;
            }

            $request = app('request');

            if (! $request instanceof Request) {
                return null;
            }

            return $request->route() !== null || ! app()->runningInConsole() || self::octane()
                ? $request
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 4xx taşıyan hata raporlanmaz: çağıranın hatası, arıza değil.
     *
     * Laravel bunları kendi dontReport listesiyle zaten eliyor, ama paket
     * log olayını da dinliyor (MessageLogged). Uygulama yakaladığı bir
     * 404'ü `Log::warning('...', ['exception' => $e])` ile yazınca
     * dontReport'u atlayıp hub'a gidiyordu. `instanceof` sınıfı otomatik
     * yüklemez; illuminate/database kurulu olmasa da güvenle çalışır.
     */
    private function clientError(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < 500;
        }

        // ModelNotFoundException bunun alt sınıfı.
        return $e instanceof RecordsNotFoundException;
    }

    private function controller(Request $request): ?string
    {
        $action = $request->route()?->getActionName();

        return is_string($action) && $action !== 'Closure' ? $action : null;
    }

    /**
     * Varsayılan kapalı (capture_user_id). Açmak kimliği belirli bir kişiye
     * bağlamak demektir ve bilinçli bir karardır.
     */
    private function userId(): int|string|null
    {
        if (! $this->config['capture_user_id']) {
            return null;
        }

        try {
            return auth()->id();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mesajı SQL taşıyan istisnalar. Sınıf adına bakılıyor çünkü paket
     * illuminate/database'e bağımlı değil ve olmamalı.
     */
    private function containsSql(Throwable $e): bool
    {
        return str_contains($e::class, 'QueryException')
            || str_contains($e::class, 'PDOException');
    }

    private function ignored(Throwable $e): bool
    {
        foreach ($this->config['ignore'] as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function alreadyReported(Throwable $e): bool
    {
        if (isset($this->reported[$e])) {
            return true;
        }

        $this->reported[$e] = true;

        return false;
    }
}
