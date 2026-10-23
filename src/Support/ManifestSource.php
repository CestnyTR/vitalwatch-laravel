<?php

namespace CestnyTR\VitalWatch\Support;

use Closure;
use Throwable;

/**
 * One dependency manifest the package reports (`composer.lock` or
 * `package-lock.json`): where it lives, how it is parsed and what it is
 * called on the wire. Scheduling and sending are shared by every source.
 *
 * Bağımlılık kaynağı — zamanlama ve gönderim kodu kaynaktan habersiz.
 * Hub listeyi (proje, ortam, ekosistem) başına tutuyor; her kaynak kendi
 * durum anahtarıyla, kendi 24 saat / özet değişimi ve 1 saatlik başarısızlık
 * kilidiyle bağımsız gönderilir.
 */
final class ManifestSource
{
    public const COMPOSER = 'composer';

    public const NPM = 'npm';

    /**
     * @param  Closure(string): ?array  $loader  yol → kanonik liste
     */
    private function __construct(
        public readonly string $id,
        public readonly string $manifest,
        public readonly string $ecosystem,
        public readonly ?string $path,
        public readonly bool $enabled,
        private readonly Closure $loader,
    ) {}

    public static function composer(?string $path): self
    {
        return new self(self::COMPOSER, Dependencies::MANIFEST, Dependencies::ECOSYSTEM, $path, true, Dependencies::load(...));
    }

    public static function npm(?string $path, bool $enabled = true): self
    {
        return new self(self::NPM, NpmDependencies::MANIFEST, NpmDependencies::ECOSYSTEM, $path, $enabled, NpmDependencies::load(...));
    }

    /** `mtime:size`; dosya yoksa null — ayrıştırmadan "değişti mi" sorusu. */
    public function fingerprint(): ?string
    {
        return $this->path !== null ? Dependencies::fingerprint($this->path) : null;
    }

    /**
     * @return array{packages: list<array{name: string, version: string}>, hash: string}|null
     */
    public function load(): ?array
    {
        try {
            return $this->path !== null ? ($this->loader)($this->path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The `dependencies` object of the request body.
     *
     * @param  array{packages: list<array{name: string, version: string}>, hash: string}  $list
     * @return array<string, mixed>
     */
    public function payload(array $list): array
    {
        return Dependencies::payload($list, $this->ecosystem, $this->manifest);
    }

    /**
     * Önbellek anahtarı. Composer 0.3.x'teki anahtarı korur — güncelleme
     * sonrası liste gereksiz yere yeniden gönderilmesin.
     */
    public function stateKey(string $base): string
    {
        return $this->id === self::COMPOSER ? $base : $base.':'.$this->id;
    }
}
