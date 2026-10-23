<?php

namespace CestnyTR\VitalWatch\Support;

use Throwable;

/**
 * Reads the production dependency list from `composer.lock` for the hub's
 * dependency advisory check. Only package names and installed versions leave
 * the application. Never throws.
 *
 * `package-lock.json` is parsed by {@see NpmDependencies}; reading, caching,
 * hashing and scheduling are shared.
 *
 * Bağımlılık listesi — dört SDK'da aynı sözleşme (v1).
 *
 * Hub listeyi OSV.dev'e karşı denetliyor. Gönderilen tek şey üretim
 * paketlerinin adı ve kurulu sürümü; `packages-dev`, yollar, kaynak
 * adresleri, betikler gönderilmez.
 *
 * Kurallar (hub ve öteki SDK'larla birebir):
 * - Sürümün başındaki `v` atılır — OSV'nin Packagist sürümlerinde `v` yok.
 * - Dal sürümleri (`dev-main`, `2.x-dev`) atlanır: OSV'de karşılıkları yok.
 * - Ada göre sıralanır (bayt sırası), en çok 5000 paket.
 * - Özet: sıralı listenin `ad@sürüm\n` satırlarının (her satır `\n` ile
 *   biter) birleşiminin sha256'sı. Hub değişikliği bununla görür.
 */
class Dependencies
{
    public const VERSION = 1;

    public const ECOSYSTEM = 'Packagist';

    public const MANIFEST = 'composer.lock';

    public const MAX_PACKAGES = 5000;

    /** Liste değişmese de günde bir kez yeniden gönderilir. */
    public const INTERVAL_SECONDS = 86400;

    /** Bundan büyük kilit dosyası okunmaz; olağan composer.lock 1 MB'ın, package-lock.json birkaç MB'ın altında. */
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /**
     * Uzun ömürlü süreçte (Octane, kuyruk işçisi) dosya her denetimde yeniden
     * ayrıştırılmasın. Anahtar yol + mtime + boyut: deploy dosyayı
     * değiştirince anahtar da değişir, eski liste raporlanmaz (Release'teki
     * "işçi deploy'dan sağ çıktı" tuzağı burada yok).
     *
     * Yol başına bir kayıt: composer.lock ve package-lock.json aynı süreçte
     * sırayla okunuyor; tek kayıt olsaydı her denetimde birbirini silip iki
     * dosyayı da yeniden ayrıştırırlardı.
     *
     * @var array<string, array{fingerprint: string, list: ?array}>
     */
    private static array $memo = [];

    /** Bellek sınırı — olağan kurulumda iki yol var; fazlası test ya da tuhaf kullanım. */
    private const MEMO_LIMIT = 8;

    /**
     * Dosyanın parmak izi (`mtime:boyut`); dosya yoksa ya da okunamıyorsa null.
     * Ayrıştırmadan "değişti mi" sorusunu cevaplar — istek başına yalnızca stat.
     */
    public static function fingerprint(string $path): ?string
    {
        try {
            // Uzun ömürlü süreçte stat önbelleği deploy'u görmezdi.
            clearstatcache(true, $path);

            if (! @is_file($path) || ! @is_readable($path)) {
                return null;
            }

            $mtime = @filemtime($path);
            $size = @filesize($path);

            if ($mtime === false || $size === false) {
                return null;
            }

            return $mtime.':'.$size;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parses `composer.lock` into the canonical list.
     *
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public static function load(string $path): ?array
    {
        return self::read($path, self::parse(...));
    }

    /**
     * Ortak okuma yolu (composer.lock ve package-lock.json): parmak izi,
     * boyut sınırı ve süreç içi önbellek. Ayrıştırıcı manifeste özgü.
     *
     * @param  \Closure(string): ?array  $parser
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public static function read(string $path, \Closure $parser): ?array
    {
        try {
            $fingerprint = self::fingerprint($path);

            if ($fingerprint === null) {
                return null;
            }

            if (isset(self::$memo[$path]) && self::$memo[$path]['fingerprint'] === $fingerprint) {
                return self::$memo[$path]['list'];
            }

            $list = null;

            if ((int) @filesize($path) <= self::MAX_FILE_BYTES) {
                $content = @file_get_contents($path);
                $list = is_string($content) ? $parser($content) : null;
            }

            if (count(self::$memo) >= self::MEMO_LIMIT && ! isset(self::$memo[$path])) {
                self::$memo = [];
            }

            self::$memo[$path] = ['fingerprint' => $fingerprint, 'list' => $list];

            return $list;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public static function parse(string $json): ?array
    {
        try {
            $lock = json_decode($json, true);

            if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
                return null;
            }

            $packages = [];

            // Yalnızca `packages` — `packages-dev` bilerek okunmuyor.
            foreach ($lock['packages'] as $package) {
                if (! is_array($package)) {
                    continue;
                }

                $name = $package['name'] ?? null;
                $version = self::normalizeVersion($package['version'] ?? null);

                if (! is_string($name) || trim($name) === '' || $version === null) {
                    continue;
                }

                $name = trim($name);
                // Aynı ad iki kez geçerse ilki kalır.
                $packages[$name] ??= $version;
            }

            ksort($packages, SORT_STRING);

            $list = [];

            foreach (array_slice($packages, 0, self::MAX_PACKAGES, true) as $name => $version) {
                $list[] = ['name' => (string) $name, 'version' => $version];
            }

            return ['packages' => $list, 'hash' => self::hash($list)];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `v7.8.1` → `7.8.1`; dal sürümü (`dev-main`, `2.x-dev`) → null.
     */
    public static function normalizeVersion(mixed $version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);
        $lower = strtolower($version);

        if ($version === '' || str_starts_with($lower, 'dev-') || str_ends_with($lower, '-dev')) {
            return null;
        }

        if (preg_match('/^[vV](?=\d)/', $version) === 1) {
            $version = substr($version, 1);
        }

        return $version;
    }

    /**
     * sha256 of the canonical list: one `name@version\n` line per package, sorted by name.
     *
     * @param  list<array{name: string, version: string}>  $packages
     */
    public static function hash(array $packages): string
    {
        $canonical = '';

        foreach ($packages as $package) {
            $canonical .= $package['name'].'@'.$package['version']."\n";
        }

        return hash('sha256', $canonical);
    }

    /**
     * The `dependencies` object of the request body.
     *
     * @param  array{packages: list<array{name: string, version: string}>, hash: string}  $list
     * @return array<string, mixed>
     */
    public static function payload(array $list, string $ecosystem = self::ECOSYSTEM, string $manifest = self::MANIFEST): array
    {
        return [
            'v' => self::VERSION,
            'ecosystem' => $ecosystem,
            'manifest' => $manifest,
            'hash' => $list['hash'],
            'packages' => $list['packages'],
        ];
    }

    /**
     * Gönderim zamanı geldi mi: hiç gönderilmemiş, liste değişmiş ya da
     * son gönderimin üzerinden 24 saat geçmiş.
     *
     * @param  array<string, mixed>|null  $state  `{hash, sent_at}`
     */
    public static function due(?array $state, string $hash, int $now): bool
    {
        if ($state === null || ! isset($state['hash'], $state['sent_at'])) {
            return true;
        }

        return $state['hash'] !== $hash || $now - (int) $state['sent_at'] >= self::INTERVAL_SECONDS;
    }

    /** Testler için: süreç içi ayrıştırma önbelleğini boşaltır. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
