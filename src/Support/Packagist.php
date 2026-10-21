<?php

namespace CestnyTR\VitalWatch\Support;

use CestnyTR\VitalWatch\Recorder;
use Throwable;

/**
 * Packagist'teki en yeni kararlı sürüm — yalnızca `vitalwatch:status` kullanır.
 *
 * İzleme yolunda hiç çağrılmaz: uygulama çalışırken dışarıya paket dışı
 * istek atılmaz. Ham curl: `Http` istemcisi Guzzle ister ve paket ona
 * bağımlı değil.
 */
class Packagist
{
    public const URL = 'https://repo.packagist.org/p2/cestnytr/vitalwatch-laravel.json';

    private const PACKAGE = 'cestnytr/vitalwatch-laravel';

    /** En yeni kararlı sürüm (`0.2.3`); ağ ya da biçim hatasında null. */
    public function latestStable(): ?string
    {
        try {
            $body = $this->fetch();

            if ($body === null) {
                return null;
            }

            $data = json_decode($body, true);

            return is_array($data) ? self::pickLatest($data) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<mixed>  $data */
    public static function pickLatest(array $data): ?string
    {
        $versions = $data['packages'][self::PACKAGE] ?? null;

        if (! is_array($versions)) {
            return null;
        }

        $latest = null;

        foreach ($versions as $entry) {
            $version = self::stable(is_array($entry) ? ($entry['version'] ?? null) : null);

            if ($version !== null && ($latest === null || self::compare($version, $latest) > 0)) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /** `v0.2.3` → `0.2.3`; dev-, beta, RC ve benzeri → null. */
    public static function stable(mixed $version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(?:\.\d+)*$/', $version) === 1 ? $version : null;
    }

    /** Sayısal karşılaştırma: `0.10.0` > `0.9.9`. */
    public static function compare(string $a, string $b): int
    {
        $x = array_map('intval', explode('.', $a));
        $y = array_map('intval', explode('.', $b));
        $n = max(count($x), count($y));

        for ($i = 0; $i < $n; $i++) {
            $cmp = ($x[$i] ?? 0) <=> ($y[$i] ?? 0);

            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** `0.3.1` → `^0.3`. */
    public static function constraint(string $version): string
    {
        $parts = explode('.', $version);

        return '^'.$parts[0].'.'.($parts[1] ?? '0');
    }

    /** Kurulu sürüm (Composer'dan); testte değiştirilir. */
    public function installed(): string
    {
        return Recorder::version();
    }

    /** Ham gövde; testte değiştirilir. */
    protected function fetch(): ?string
    {
        $ch = curl_init(self::URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'vitalwatch-status',
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($body) && $status === 200 ? $body : null;
    }
}
