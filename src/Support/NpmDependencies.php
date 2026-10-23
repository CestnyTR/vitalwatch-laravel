<?php

namespace CestnyTR\VitalWatch\Support;

use Throwable;

/**
 * Reads the production npm dependency list from `package-lock.json` — the
 * packages Vite bundles into the JavaScript served to visitors. Only package
 * names and installed versions leave the application. Never throws.
 *
 * Node SDK'daki `src/dependencies.js` ayrıştırıcısının birebir karşılığı;
 * hub aynı kilit dosyası için aynı listeyi ve aynı özeti görmeli.
 *
 * - lockfileVersion 2/3: `packages` haritası. Kök (`""`) ve `node_modules/`
 *   içermeyen anahtarlar (çalışma alanının kendi kaynak klasörü) atlanır. Ad
 *   son `node_modules/`'ten sonrası; takma adda (`npm:gercek@1`) `name` alanı.
 *   `dev: true`, `link: true` ve sürümsüz girdiler atlanır; `optional` ve
 *   `devOptional` kalır (üretimde kurulabilirler).
 * - lockfileVersion 1: iç içe `dependencies` ağacı; `dev: true` ve `file:`
 *   sürümleri atlanır.
 * - Aynı ad@sürüm tekilleşir, farklı sürümler ayrı kalır (ikisi de açıklı
 *   olabilir). Ada, sonra sürüme göre bayt sırası; en çok 5000 paket; özet
 *   bu listenin üzerinden.
 *
 * pnpm/yarn/bun kilit dosyaları okunmaz: ayrı ayrıştırıcı (YAML) ister,
 * bağımlılık eklemek yasak. O projelerde npm listesi gönderilmez.
 */
class NpmDependencies
{
    public const ECOSYSTEM = 'npm';

    public const MANIFEST = 'package-lock.json';

    /** Bozuk ya da döngülü v1 ağacına karşı derinlik sınırı (Node SDK ile aynı). */
    private const MAX_DEPTH = 64;

    /**
     * Parses `package-lock.json` at `$path` into the canonical list (cached by path, mtime and size).
     *
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public static function load(string $path): ?array
    {
        return Dependencies::read($path, self::parse(...));
    }

    /**
     * Geçersiz JSON ya da nesne olmayan kök → null (gönderilecek bir şey yok).
     *
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public static function parse(string $json): ?array
    {
        try {
            $lock = json_decode($json, true);

            if (! is_array($lock)) {
                return null;
            }

            $packages = self::packages($lock);

            return ['packages' => $packages, 'hash' => Dependencies::hash($packages)];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Kilit dosyası içeriğinden kanonik liste (Node: `parseLockfile`).
     *
     * @return list<array{name: string, version: string}>
     */
    public static function packages(mixed $lock): array
    {
        $found = [];

        try {
            if (is_array($lock)) {
                // v2 iki biçimi birden taşır; `packages` daha doğru (iç içe
                // kurulumları ve takma adları tam gösterir), o öncelikli.
                if (is_array($lock['packages'] ?? null)) {
                    self::fromPackages($lock['packages'], $found);
                } elseif (is_array($lock['dependencies'] ?? null)) {
                    self::fromDependencyTree($lock['dependencies'], $found, 0);
                }
            }
        } catch (Throwable) {
            // Bozuk girdi — ne bulunduysa o.
        }

        return self::canonical($found);
    }

    /**
     * lockfileVersion 2/3: `node_modules/a/node_modules/b` biçimli düz harita.
     *
     * @param  array<array-key, mixed>  $packages
     * @param  list<array{name: string, version: string}>  $found
     */
    private static function fromPackages(array $packages, array &$found): void
    {
        foreach ($packages as $key => $entry) {
            // JSON'daki sayısal anahtarlar PHP'de int olur; dizgeye çevrilir.
            $key = (string) $key;

            if ($key === '' || ! is_array($entry)) {
                continue;
            }

            if (($entry['dev'] ?? null) === true || ($entry['link'] ?? null) === true) {
                continue;
            }

            $marker = strrpos($key, 'node_modules/');

            // node_modules altında olmayan anahtar çalışma alanının (workspace)
            // kendi kaynak klasörü — uygulamanın kendi kodu, kayıt paketi değil.
            if ($marker === false) {
                continue;
            }

            // Takma ad anahtarda takma adı, `name` alanında gerçek paketi taşır;
            // açık veritabanı gerçek adı tanır.
            $name = is_string($entry['name'] ?? null) && $entry['name'] !== ''
                ? $entry['name']
                : substr($key, $marker + strlen('node_modules/'));

            self::push($found, $name, $entry['version'] ?? null);
        }
    }

    /**
     * lockfileVersion 1: iç içe `dependencies` ağacı.
     *
     * @param  array<array-key, mixed>  $tree
     * @param  list<array{name: string, version: string}>  $found
     */
    private static function fromDependencyTree(array $tree, array &$found, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($tree as $name => $entry) {
            if (! is_array($entry) || ($entry['dev'] ?? null) === true) {
                continue;
            }

            $version = $entry['version'] ?? null;

            // v1'de yerel bağlantılar `file:` sürümüyle yazılır (v2'deki link: true).
            if (is_string($version) && ! str_starts_with($version, 'file:')) {
                self::push($found, (string) $name, $version);
            }

            if (is_array($entry['dependencies'] ?? null)) {
                self::fromDependencyTree($entry['dependencies'], $found, $depth + 1);
            }
        }
    }

    /** @param  list<array{name: string, version: string}>  $found */
    private static function push(array &$found, mixed $name, mixed $version): void
    {
        if (! is_string($name) || ! is_string($version)) {
            return;
        }

        $name = trim($name);
        $version = trim($version);

        if ($name !== '' && $version !== '') {
            $found[] = ['name' => $name, 'version' => $version];
        }
    }

    /**
     * Tekilleştirir, ada sonra sürüme göre sıralar, 5000'de keser.
     *
     * strcmp bayt karşılaştırması; Node UTF-16 kod birimiyle karşılaştırıyor.
     * İkisi ASCII'de (npm paket adları) ve BMP'nin vekil çifti dışındaki
     * bölümünde aynı sırayı verir — özetler eşleşir.
     *
     * @param  list<array{name: string, version: string}>  $list
     * @return list<array{name: string, version: string}>
     */
    private static function canonical(array $list): array
    {
        $seen = [];
        $unique = [];

        foreach ($list as $item) {
            // `@` içerdiği için anahtar hiçbir zaman sayısal dizgeye dönmez.
            $id = $item['name'].'@'.$item['version'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $unique[] = $item;
        }

        usort($unique, fn (array $a, array $b) => strcmp($a['name'], $b['name']) ?: strcmp($a['version'], $b['version']));

        return array_slice($unique, 0, Dependencies::MAX_PACKAGES);
    }
}
