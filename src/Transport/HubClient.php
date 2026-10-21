<?php

namespace CestnyTR\VitalWatch\Transport;

use Throwable;

/**
 * Hub'a HMAC imzalı POST.
 *
 * Davranış garantisi: **hiçbir koşulda istisna fırlatmaz.** Hub erişilemezse,
 * yapılandırma eksikse veya ağ koparsa sessizce vazgeçilir — izlenen
 * uygulamada hata, log kirliliği veya yavaşlama oluşmaz.
 *
 * Ham curl kullanılır: bağımlılık politikası gereği ek paket eklenmez ve
 * zaman aşımı üzerinde tam denetim gerekir.
 */
class HubClient
{
    /**
     * Hub'ın gövde sınırı (hub §8.3). Aşan istek okunmadan atılır ve yine
     * 204 döner — sessiz kayıp. Alanlar karakterle kesiliyor, bayt değil:
     * Türkçe harf 2 bayt, stack'teki `App\Http\...` ters eğik çizgileri
     * JSON'da ikiye katlanıyor.
     */
    public const MAX_BODY_BYTES = 8192;

    /** Zaman aşımı, milisaniye. */
    private readonly int $timeoutMs;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly ?string $secret,
        mixed $timeout,
    ) {
        $this->timeoutMs = (int) round(self::timeoutSeconds($timeout) * 1000);
    }

    /** Varsayılan ve sınırlar, saniye. Kural bütün SDK'larda aynı. */
    private const TIMEOUT_DEFAULT = 2.0;

    private const TIMEOUT_MIN = 0.1;

    private const TIMEOUT_MAX = 10.0;

    /**
     * `VITALWATCH_TIMEOUT` saniye; 100 ve üstü milisaniye sayılır.
     *
     * Node paketi aynı adla milisaniye bekliyor. `2000` yazan birinin PHP
     * sürecini hub'a 2000 saniye bağlaması, izleme paketinin uygulamayı
     * kilitlemesi demekti. Geçersiz, sıfır, negatif ya da sonsuz değer
     * varsayılana (2 sn) döner; sonuç [0,1 sn, 10 sn] aralığına kırpılır.
     * Kırpma şart: `0.0001` milisaniyeye çevrilince 0 oluyordu ve curl'de
     * 0 "sınırsız" demek.
     */
    public static function timeoutSeconds(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        if (! is_finite($number) || $number <= 0) {
            return self::TIMEOUT_DEFAULT;
        }

        $seconds = $number >= 100 ? $number / 1000 : $number;

        return min(self::TIMEOUT_MAX, max(self::TIMEOUT_MIN, $seconds));
    }

    public function configured(): bool
    {
        return ! empty($this->url) && ! empty($this->key) && ! empty($this->secret);
    }

    /**
     * Sonuç döndürür ama **asla istisna fırlatmaz.**
     *
     * Sonucu yalnızca teşhis komutu okuyor; izleme yolu görmezden geliyor.
     * Gönderim başarısızsa yapılacak bir şey yok, izlenen uygulamayı bundan
     * haberdar etmek log kirliliğinden başka işe yaramaz (davranış garantisi 4).
     *
     * Yine de sonucu üretmek zorunlu: `vitalwatch:status --test` "gönderildi" derken
     * gerçekte hiçbir şey gitmemiş olabiliyordu ve kuran kişi kurulumu çalışır
     * sanıyordu. Teşhis aracının yanlış teşhis koyması, hiç teşhis koymamaktan
     * kötüdür.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function send(array $payload): array
    {
        if (! $this->configured()) {
            return ['sent' => false, 'error' => 'missing-config'];
        }

        try {
            $body = self::encode($payload);

            if ($body === null) {
                return ['sent' => false, 'error' => 'body-too-large'];
            }

            $timestamp = (string) time();

            // Zaman damgası imzaya dahildir; olmasaydı saldırgan damgayı
            // değiştirip eski bir gövdeyi yeniden oynatabilirdi.
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

            $ch = curl_init(rtrim($this->url, '/').'/api/i/'.$this->key.'/server');

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                // _MS: saniye tam sayı; 0.5 sn sıfıra, yani sınırsıza dönerdi.
                CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                // Saniye altı zaman aşımında eşzamanlı çözücü SIGALRM
                // kullanıyor; çok iş parçacıklı sunucuda (FrankenPHP) güvensiz,
                // bazı derlemelerde DNS anında zaman aşımına düşüyor.
                CURLOPT_NOSIGNAL => true,
                // Yönlendirme takip edilmez: imzalı gövdeyi bilinmeyen bir
                // adrese göndermek istemeyiz.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-VitalWatch-Signature: sha256='.$signature,
                    'X-VitalWatch-Timestamp: '.$timestamp,
                ],
            ]);

            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false || $error !== '') {
                return ['sent' => false, 'error' => $error !== '' ? $error : 'curl-failed'];
            }

            /*
            | Hub başarıda da geçersiz istekte de 204 döner (saldırgana geri
            | bildirim verilmez). Yani 204 "kabul edildi" demek DEĞİL, yalnızca
            | "istek ulaştı" demek. Teşhis komutu bunu açıkça yazıyor.
            */
            return ['sent' => $status > 0 && $status < 400, 'status' => $status];
        } catch (Throwable $e) {
            // Sessizce vazgeç. İzleme paketinin izlediği uygulamayı bozması,
            // çözdüğü sorundan büyük bir sorundur.
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * JSON'a çevirir ve hub sınırına sığdırır; sığmazsa null.
     *
     * Sıra Python paketiyle aynı: önce stack, sonra mesaj 200 karaktere,
     * sonra en yavaş sorgu. Olayı hiç göndermemektense stack'siz göndermek
     * yeğdir — sınıf, mesaj, dosya ve satır yine ulaşır.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): ?string
    {
        /*
        | Geçersiz UTF-8 json_encode'u false'a düşürür ve olay hiç gitmez;
        | bozuk bayt `\u{FFFD}` ile değiştirilir.
        */
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        $steps = [
            fn (array $p) => array_diff_key($p, ['stack' => true]),
            fn (array $p) => isset($p['msg']) && is_string($p['msg'])
                ? [...$p, 'msg' => mb_substr($p['msg'], 0, 200)]
                : $p,
            fn (array $p) => array_diff_key($p, ['slowest_query_sql' => true]),
        ];

        $body = json_encode($payload, $flags);

        foreach ($steps as $step) {
            if ($body !== false && strlen($body) <= self::MAX_BODY_BYTES) {
                return $body;
            }

            $payload = $step($payload);
            $body = json_encode($payload, $flags);
        }

        return $body !== false && strlen($body) <= self::MAX_BODY_BYTES ? $body : null;
    }
}
