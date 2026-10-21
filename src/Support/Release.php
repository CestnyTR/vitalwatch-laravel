<?php

namespace CestnyTR\VitalWatch\Support;

use Throwable;

/**
 * Sürüm etiketi (release) — Node ve Python paketleriyle aynı kural.
 *
 * VITALWATCH_RELEASE neredeyse hiçbir kurulumda doldurulmuyor; hub bir hatanın
 * hangi deploy'la başladığını söyleyemiyordu. Deploy'larımız `git pull`
 * olduğu için `.git` sunucuda duruyor: etiket oradan okunabilir.
 *
 * Sıra: VITALWATCH_RELEASE → CI/PaaS ortam değişkenleri → `.git` → yok.
 *
 * **Önbellek yok (bilerek).** Statik bir önbellek FPM işçisinde istekler
 * arasında yaşar; işçi deploy'dan sağ çıkınca eski commit'i raporlamaya
 * devam ederdi — tam da çözmeye çalıştığımız "hangi sürüm" sorusunu yanlış
 * cevaplamak. Açılış başına iki küçük dosya okumak ihmal edilebilir.
 *
 * Hiçbir koşulda istisna fırlatmaz; kabuk komutu (`git rev-parse`)
 * çalıştırmaz.
 */
class Release
{
    /** Sırayla denenir; ilk dolu olan kazanır. */
    public const ENV_VARS = [
        'GIT_COMMIT',
        'GIT_SHA',
        'COMMIT_SHA',
        'SOURCE_VERSION',
        'VERCEL_GIT_COMMIT_SHA',
        'RENDER_GIT_COMMIT',
        'HEROKU_SLUG_COMMIT',
        'CI_COMMIT_SHA',
    ];

    private const MAX_LENGTH = 64;

    private const SHORT_SHA = 12;

    /**
     * @param  mixed  $configured  config('vitalwatch.release') — doluysa kazanır.
     * @return array{value: ?string, source: string}
     */
    public static function detect(string $basePath, mixed $configured = null): array
    {
        try {
            $value = self::clean($configured);

            if ($value !== null) {
                return ['value' => $value, 'source' => 'VITALWATCH_RELEASE'];
            }

            foreach (self::ENV_VARS as $name) {
                $value = self::clean(self::env($name));

                if ($value !== null) {
                    return ['value' => self::fromEnv($value), 'source' => $name];
                }
            }

            $sha = self::fromGit($basePath);

            if ($sha !== null) {
                return ['value' => $sha, 'source' => '.git'];
            }
        } catch (Throwable) {
            // Sürüm bulunamadı; raporlama durmaz.
        }

        return ['value' => null, 'source' => 'none'];
    }

    /** Commit gibi görünen değer kısaltılır; değilse olduğu gibi (64'e kadar). */
    private static function fromEnv(string $value): string
    {
        if (preg_match('/^[0-9a-f]{7,40}$/i', $value) === 1) {
            return substr(strtolower($value), 0, self::SHORT_SHA);
        }

        return $value;
    }

    /**
     * `config:cache` sonrası `env()` null döner; bu yüzden değişken yapılandırma
     * dosyasında değil, çalışma anında okunur.
     */
    private static function env(string $name): ?string
    {
        foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LENGTH);
    }

    private static function fromGit(string $basePath): ?string
    {
        $base = rtrim($basePath, '/\\');
        $gitDir = self::gitDir($base);

        if ($gitDir === null) {
            return null;
        }

        $head = self::read($gitDir.'/HEAD');

        if ($head === null) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));

            // Yalnızca refs/ altı; `..` ile depo dışına çıkılmaz.
            if (! str_starts_with($ref, 'refs/') || str_contains($ref, '..')) {
                return null;
            }

            $sha = self::read($gitDir.'/'.$ref);

            return self::sha($sha !== null ? trim($sha) : self::packedRef($gitDir, $ref));
        }

        // Ayrık HEAD: doğrudan commit.
        return self::sha($head);
    }

    /** `.git` dizin ya da `gitdir: <yol>` dosyası (alt modül, worktree). */
    private static function gitDir(string $base): ?string
    {
        $dotGit = $base.'/.git';

        if (@is_dir($dotGit)) {
            return $dotGit;
        }

        if (! @is_file($dotGit)) {
            return null;
        }

        $content = self::read($dotGit);

        if ($content === null || preg_match('/^gitdir:\s*(.+)$/m', $content, $m) !== 1) {
            return null;
        }

        $path = rtrim(trim($m[1]), '/\\');

        // Göreli yol proje köküne göre.
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = $base.'/'.$path;
        }

        return @is_dir($path) ? $path : null;
    }

    private static function packedRef(string $gitDir, string $ref): ?string
    {
        $content = self::read($gitDir.'/packed-refs');

        if ($content === null) {
            return null;
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);

            if (count($parts) === 2 && $parts[1] === $ref) {
                return $parts[0];
            }
        }

        return null;
    }

    private static function sha(?string $value): ?string
    {
        if ($value === null || preg_match('/^[0-9a-f]{40}$/i', $value) !== 1) {
            return null;
        }

        return substr(strtolower($value), 0, self::SHORT_SHA);
    }

    private static function read(string $path): ?string
    {
        if (! @is_file($path) || ! @is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return is_string($content) ? $content : null;
    }
}
