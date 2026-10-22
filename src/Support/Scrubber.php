<?php

namespace CestnyTR\VitalWatch\Support;

/**
 * Kişisel veri temizliği — gönderimden ÖNCE uygulanır.
 *
 * Hub tarafında aynı temizlik tekrar yapılır. İkisi de gereklidir: hub kendini
 * savunur, paket ise kişisel veriyi ağa hiç çıkarmaz. Bir sızıntı olacaksa
 * ilk savunma hattı burasıdır.
 */
class Scrubber
{
    /**
     * Sıra önemlidir: IBAN ve kart numarası rakam dizileridir, telefon
     * deseninden önce denenmezse o desen onları parçalar.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u' => '[email]',
        '/\bTR(?:[\s-]?\d){24}\b/i' => '[iban]',
        // Aday; karar isCard'da — dosya adındaki zaman damgası kart sanılmasın.
        '/\b\d(?:[\s-]?\d){12,18}\b/' => '[card]',
        '/\b[1-9]\d{10}\b/' => '[national_id]',
        // Önünde rakam olamaz: `1795123456789` damgası `179[phone]` oluyordu.
        '/(?<!\d)(?:\+?90|0)?[\s-]?5\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}\b/' => '[phone]',

        /*
        | Uzun rastgele diziler: oturum kimliği, API anahtarı, jeton, hash.
        | Gerçek bir sızıntıda yakalandı — QueryException'ın mesajı SQL'i
        | bağlanmış değerlerle taşıyor ve orada oturum kimliği vardı:
        |   select * from "sessions" where "id" = ItLsnnji2VLJtiE1SjiyAEdzv...
        |
        | 24 hane eşiği bilinçli: Laravel oturum kimliği 40, API anahtarları
        | 32+; normal kelimeler ve sınıf adları bu uzunluğa ulaşmaz.
        |
        | Alt çizgi ve tire dahil: `<onek>_<uzun-dizi>` biçimindeki jetonlar
        | sözcük sınırıyla aranınca kaçıyordu.
        */
        '/[A-Za-z0-9][A-Za-z0-9_\-]{23,}/' => '[token]',
    ];

    /*
    | Bu iki desen aday bulur, karar isCard/isToken'da. Kurallar hub ve öbür
    | SDK'larla birebir aynı; ayrışırsa parmak izi bölünür.
    */
    private const CHECKED = ['[card]' => 'isCard', '[token]' => 'isToken'];

    public static function text(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        /*
        | Önce UTF-8 onarılır. Eposta deseni `/u` bayraklı; geçersiz baytta
        | preg_replace null dönüyor, `?? $value` ile desen sessizce
        | atlanıyor ve adres maskelenmeden gidiyordu (latin1 veritabanı
        | hatasında gerçekleşebilir).
        */
        $value = mb_scrub($value, 'UTF-8');

        foreach (self::PATTERNS as $pattern => $replacement) {
            $check = self::CHECKED[$replacement] ?? null;

            $value = ($check === null
                ? preg_replace($pattern, $replacement, $value)
                : preg_replace_callback(
                    $pattern,
                    fn (array $m) => self::$check($m[0]) ? $replacement : $m[0],
                    $value,
                )) ?? $value;
        }

        return mb_substr($value, 0, $limit);
    }

    /**
     * Gerçek kart numarası: 2-9 ile başlar ve Luhn'dan geçer. Zaman damgası
     * (1 ile başlar) kart sanılmaz; her gerçek kart Luhn'u geçer.
     */
    private static function isCard(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';

        if ($digits === '' || $digits[0] < '2') {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];

            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }

            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Rastgele dizi: 16+ karakterlik bölünmemiş parça ya da harf içeren hex
     * (UUID, hash). Tireyle birleşmiş kısa parçalar okunur addır.
     */
    private static function isToken(string $candidate): bool
    {
        // Düz CamelCase/camelCase ad jeton değildir: gerçek jeton ve oturum
        // kimlikleri neredeyse hep rakam taşır; rakamsız, yan yana iki büyük
        // harfi olmayan ve küçük harfle biten 24+ karakterlik rastgele dizinin
        // olasılığı 1/10.000'in çok altında. .NET'in `InvalidOperationException`
        // gibi adları maskeleniyor, yığın izleri okunmaz oluyordu.
        if (preg_match('/^[A-Za-z][a-z]+(?:[A-Z][a-z]+)+$/D', $candidate) === 1) {
            return false;
        }

        $parts = preg_split('/[-_]/', $candidate) ?: [];

        if (max(array_map('strlen', $parts)) >= 16) {
            return true;
        }

        $compact = str_replace(['-', '_'], '', $candidate);

        return preg_match('/^[0-9a-f]+$/i', $compact) === 1
            && preg_match('/[a-f]/i', $compact) === 1;
    }

    /** Query string ve fragment atılır; yalnızca yol kalır. */
    public static function path(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH) ?: strtok($value, '?#');

        if (! is_string($path) || $path === '') {
            return null;
        }

        return self::text($path, 300);
    }

    /**
     * SQL normalize: literal değerler `?` ile değiştirilir.
     * `where email = 'ahmet@ornek.com'` → `where email = ?`
     *
     * Bu hem KVKK gereği hem gruplama açısından doğrudur: aynı sorgu farklı
     * parametrelerle çalıştığında tek parmak izinde toplanır.
     */
    public static function sql(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = preg_replace("/'(?:[^']|'')*'/", '?', $value) ?? $value;
        $value = preg_replace('/"(?:[^"]|"")*"/', '?', $value) ?? $value;
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $value) ?? $value;
        $value = preg_replace('/\b(IN)\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', '$1 (?)', $value) ?? $value;

        return self::text(preg_replace('/\s+/', ' ', trim($value)), 500);
    }

    /**
     * Exception mesajı.
     *
     * QueryException'ın mesajı SQL'i bağlanmış değerlerle birlikte taşır;
     * önce SQL normalize edilir, sonra genel maskeleme uygulanır. Yalnızca
     * `slowest_query_sql` alanını normalize etmek yetmiyordu — asıl sızıntı
     * mesajın kendisinden oluyordu.
     */
    public static function message(?string $value, bool $sqlIceriyor = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($sqlIceriyor) {
            $value = preg_replace("/'(?:[^']|'')*'/", '?', $value) ?? $value;
            $value = preg_replace('/"(?:[^"]|"")*"(\s*=\s*)\S+/', '"?"$1?', $value) ?? $value;
        }

        return self::text($value, 500);
    }

    /**
     * Stack trace kısaltılır ve maskelenir. Vendor satırları atılmaz —
     * hatanın nerede olduğunu bulmak için zincirin tamamı gerekir — ama
     * uzunluk sınırlanır.
     */
    public static function stack(?string $value): ?string
    {
        return self::text($value, 2000);
    }
}
