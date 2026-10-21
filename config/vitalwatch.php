<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Açma / kapama
    |--------------------------------------------------------------------------
    |
    | false iken HİÇBİR kanca kurulmaz — sıfır ek yük. Sorun şüphesinde
    | uygulamayı yeniden dağıtmadan izlemeyi kapatmanın yolu budur.
    |
    */

    'enabled' => env('VITALWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Hub bağlantısı
    |--------------------------------------------------------------------------
    |
    | secret YALNIZCA sunucuda bulunur; tarayıcıya konulmaz. Tarayıcı hataları
    | için ayrı, anahtar tabanlı t.js kullanılır.
    |
    */

    'url' => env('VITALWATCH_URL'),
    'key' => env('VITALWATCH_KEY'),
    'secret' => env('VITALWATCH_SECRET'),

    // Boş bırakılırsa uygulamanın kendi ortamı kullanılır.
    'env' => env('VITALWATCH_ENV'),

    // Deploy etiketi: hatanın hangi sürümle geldiğini bulmak için. Boşsa
    // CI değişkeninden ya da `.git`'ten çalışma anında bulunur (bkz. Release).
    'release' => env('VITALWATCH_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Eşikler
    |--------------------------------------------------------------------------
    |
    | Her istek gönderilmez, yalnızca eşiği aşanlar. Böylece hacim düşük kalır
    | ve gelen her kayıt gerçekten ilgilenilmesi gereken bir kayıttır.
    |
    */

    'slow_request_ms' => (int) env('VITALWATCH_SLOW_REQUEST_MS', 1000),
    'slow_query_ms' => (int) env('VITALWATCH_SLOW_QUERY_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Kişisel veri
    |--------------------------------------------------------------------------
    |
    | user_id kimliği belirli bir kişiye bağlar. Varsayılan kapalı; açmak
    | bilinçli bir karardır ve KVKK açısından ayrıca değerlendirilmelidir.
    |
    | IP, User-Agent, istek gövdesi, query string değerleri, oturum ve çerez
    | verisi bu ayarla dahi toplanamaz — kodda karşılıkları yoktur.
    |
    */

    'capture_user_id' => (bool) env('VITALWATCH_CAPTURE_USER_ID', false),

    /*
    |--------------------------------------------------------------------------
    | Yok sayılacak exception sınıfları
    |--------------------------------------------------------------------------
    |
    | Beklenen akış istisnaları panelde gürültü üretir. Alt sınıflar da eşleşir.
    |
    */

    'ignore' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ValidationException::class,
        TokenMismatchException::class,
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ağ
    |--------------------------------------------------------------------------
    |
    | Kısa tutulur: raporlama isteği terminate aşamasında gönderilse bile
    | PHP sürecini meşgul eder. Hub yanıt vermezse sessizce vazgeçilir.
    |
    */

    /*
    | Saniye. 100 ve üstü milisaniye sayılır (bkz. HubClient::timeoutSeconds):
    | Node paketi milisaniye bekliyor ve aynı adla `VITALWATCH_TIMEOUT=2000`
    | yazılınca PHP süreci hub'ı 2000 saniye beklerdi. Eskiden `(int)` ile
    | okunuyor, `0.5` sıfıra — sınırsız beklemeye — dönüyordu. Geçersiz,
    | sıfır, negatif ya da sonsuz değer 2 sn olur; sonuç 0,1–10 sn
    | aralığına kırpılır.
    */
    'timeout' => env('VITALWATCH_TIMEOUT', 2),

];
