<?php

namespace CestnyTR\VitalWatch\Http\Middleware;

use CestnyTR\VitalWatch\Recorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Measures request duration and reports slow requests and 5xx responses.
 * Registered automatically by the service provider.
 *
 * İstek süresini ölçer ve eşiği aşanları raporlar.
 *
 * Ölçüm `handle`'da başlar, gönderim `terminate`'te yapılır: yanıt kullanıcıya
 * çoktan iletilmiştir, raporlama kimseyi bekletmez.
 */
class MeasureRequest
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Başlangıç zamanı Recorder'da tutuluyor: Laravel terminate() için
        // middleware'i konteynerdan YENİDEN çözüyor, bu örnekteki alan orada
        // boş kalırdı.
        //
        // LARAVEL_START uygulamanın gerçek başlangıcıdır; framework'ün
        // önyükleme süresi de ölçüme dahil olsun.
        //
        // Octane'da LARAVEL_START işçinin açıldığı an: her istek saatlerce
        // sürmüş, yani "yavaş" görünürdü. Orada istek anı kullanılıyor.
        $this->recorder->startRequest(
            defined('LARAVEL_START') && ! Recorder::octane() ? LARAVEL_START : microtime(true),
        );

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->recorder->recordRequest($request, $response);

            /*
            | Canlılık nabzı da burada: yanıt gönderildikten sonra, kimseyi
            | bekletmeden. Sekiz saatte bir gerçekten istek atar, aradaki her
            | çağrıda yalnızca bir önbellek okuması yapar.
            |
            | Zamanlayıcı yerine isteğe bağlı olmasının gerekçesi
            | Recorder::heartbeatIfDue() içinde.
            */
            $this->recorder->heartbeatIfDue();
        } catch (Throwable) {
            // Paket kendi hatasıyla uygulamayı etkilemez.
        }
    }
}
