<?php

namespace CestnyTR\VitalWatch\Console;

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Environment;
use CestnyTR\VitalWatch\Support\Packagist;
use CestnyTR\VitalWatch\Support\Release;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan vitalwatch:status` — checks the local VitalWatch setup and,
 * with `--test` / `--vitalwatch` / `--deps`, sends a test event, a liveness
 * request or the production dependency lists.
 *
 * Kurulum teşhisi.
 *
 * Bu komut, paketin en pahalı arıza biçimi için var: **sessiz çalışmama.**
 * Yapılandırma eksikken ya da secret yanlışken hiçbir şey patlamaz, hiçbir
 * log düşmez — hub geçersiz isteğe de 204 döner (saldırgana geri bildirim
 * verilmez). Sonuç, kimsenin fark etmediği bir izleme kurulumu.
 *
 * Komut tek başına "çalışıyor" diyemez: hub geçerli ile geçersiz imzayı
 * dışarıya aynı yanıtla karşılar. Yaptığı iş, yerel tarafta yanlış olan ne
 * varsa göstermek ve doğrulamanın hub panelinden yapılacağını söylemek.
 */
class StatusCommand extends Command
{
    protected $signature = 'vitalwatch:status
                            {--test : Send a test event to the hub — shows up as an error in the panel}
                            {--vitalwatch : Send a liveness request only — no error in the panel}
                            {--deps : Send the production dependency lists (composer.lock, package-lock.json) to the hub now}';

    protected $description = 'Check the VitalWatch installation';

    /** Hub'ın ürettiği secret bu uzunlukta; farklıysa eksik kopyalanmıştır. */
    private const SECRET_LENGTH = 64;

    /**
     * HubClient'a bilerek sorulmuyor: o singleton yapılandırmayı boot anında
     * dondurup saklıyor. Teşhis komutunun **güncel** yapılandırmayı raporlaması
     * gerekir, boot'ta ne varsa onu değil.
     */
    public function handle(): int
    {
        $secret = (string) config('vitalwatch.secret');

        $this->newLine();
        /*
        | Sürüm en üstte: "güncelleme geçti mi" sorusunun cevabı bu ve
        | onlarca kurulumda en sık sorulan şey o. Yapılandırma doğru olsa
        | bile eski sürüm eski davranışı sürdürür.
        */
        $this->row('SDK version', Recorder::version());
        $this->updateCheck();
        $this->row('Enabled', config('vitalwatch.enabled') ? 'yes' : 'NO (VITALWATCH_ENABLED=false)');
        $this->row('Hub URL', (string) config('vitalwatch.url') ?: 'NOT SET');
        $this->row('Project key', (string) config('vitalwatch.key') ?: 'NOT SET');
        $this->row('Secret length', $secret === '' ? 'NOT SET' : strlen($secret).' characters');
        $envRaw = (string) (config('vitalwatch.env') ?: app()->environment());
        $envName = Environment::normalize($envRaw);
        $this->row('Environment', $envName === $envRaw ? $envName : "{$envName} ({$envRaw})");
        $release = Release::detect(base_path(), config('vitalwatch.release'));
        $this->row('Release tag', sprintf('%s (source: %s)', $release['value'] ?? 'not set', $release['source']));
        $this->dependencyRows();
        $this->newLine();

        $problems = $this->problems($secret);

        foreach ($problems as $problem) {
            $this->error('  ✗ '.$problem);
        }

        if ($problems !== []) {
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  ✓ Configuration OK.');

        if ($this->option('test') && ! $this->sendProbe()) {
            return self::FAILURE;
        }

        if ($this->option('vitalwatch') && ! $this->sendHeartbeat()) {
            return self::FAILURE;
        }

        if ($this->option('deps') && ! $this->sendDependencies()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Only the hub panel can confirm that data actually arrives:');
        $this->line('  the connection status on the project\'s row should read "Connected".');
        $this->line('  The hub answers 204 even to an invalid signature, so this command cannot tell.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function problems(string $secret): array
    {
        $problems = [];

        if (! config('vitalwatch.enabled')) {
            $problems[] = 'VITALWATCH_ENABLED=false — no hooks are installed and no data is sent.';
        }

        $envRaw = (string) (config('vitalwatch.env') ?: app()->environment());

        if (! Environment::accepted(Environment::normalize($envRaw))) {
            $problems[] = "Environment \"{$envRaw}\" is not accepted by the hub — events and liveness requests are silently dropped. Set VITALWATCH_ENV to production, staging or local.";
        }

        $missing = collect(['VITALWATCH_URL' => 'url', 'VITALWATCH_KEY' => 'key', 'VITALWATCH_SECRET' => 'secret'])
            ->filter(fn (string $key) => empty(config("vitalwatch.{$key}")))
            ->keys();

        if ($missing->isNotEmpty()) {
            $problems[] = 'Not set: '.$missing->implode(', ').'.';
        }

        if ($secret !== '' && strlen($secret) !== self::SECRET_LENGTH) {
            // En sık hata bu: secret kopyalanırken başı veya sonu eksik
            // kalıyor ve sonuç sessizce hiçbir şey göndermemek oluyor.
            $problems[] = sprintf(
                'VITALWATCH_SECRET must be %d characters, got %d. It may have been copied incompletely.',
                self::SECRET_LENGTH,
                strlen($secret),
            );
        }

        if (str_starts_with((string) config('vitalwatch.url'), 'http://')) {
            $problems[] = 'VITALWATCH_URL starts with http:// — the signed requests travel over an unencrypted connection.';
        }

        return $problems;
    }

    /**
     * Sonuç okunuyor, "gönderdim" varsayılmıyor.
     *
     * Önceden koşulsuz başarı yazılıyordu: ağ koptuysa, zaman aşımı olduysa ya
     * da hub reddettiyse komut yine "✓ gönderildi" diyordu ve kuran kişi
     * kurulumu çalışır sanıyordu. Gerçek bir kurulumda tam olarak bu yaşandı.
     */
    /**
     * Canlılık isteği — bağlantıyı panele hata düşürmeden sınar.
     *
     * `--test` gerçek bir istisna gönderiyor ve bu tek kurulumu doğrularken
     * doğru: taşıma ve exception kancası birlikte sınanmış oluyor. Ama
     * onlarca kurulumu tek tek gezen bir döngüde aynı şey panele onlarca
     * sahte hata bırakır — izleme aracının kendi gürültüsünü üretmesi.
     *
     * Canlılık isteği bunu kirletmeden yapıyor: olay taşımıyor ama kabul edildiğinde
     * projenin bağlantı durumunu tazeliyor.
     */
    private function sendHeartbeat(): bool
    {
        $result = app(Recorder::class)->heartbeat();

        if ($result['sent'] ?? false) {
            $this->info(sprintf('  ✓ Liveness request sent (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Liveness request NOT SENT: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'unknown'),
        ));
        $this->newLine();

        return false;
    }

    /** Güncel yapılandırma okunur: iki bayraktan biri kapalıysa özellik kapalı. */
    private function dependenciesEnabled(): bool
    {
        return (bool) config('vitalwatch.enabled') && (bool) config('vitalwatch.dependencies', true);
    }

    /**
     * Kilit dosyası başına bir satır; ikincisi etiketsiz, aynı sütunda:
     *
     *   Dependencies       composer.lock · 126 packages · last sent 3 h ago
     *                      package-lock.json · 214 packages · not sent yet
     */
    private function dependencyRows(): void
    {
        if (! $this->dependenciesEnabled()) {
            $this->row('Dependencies', 'disabled');

            return;
        }

        $label = 'Dependencies';

        foreach (app(Recorder::class)->dependencyInfo() as $info) {
            $this->row($label, $this->dependencyLine($info));
            $label = '';
        }
    }

    /**
     * `composer.lock · 142 packages · last sent 3 h ago`
     *
     * @param  array{manifest: string, enabled: bool, packages: ?int, sent_at: ?int, cache: bool}  $info
     */
    private function dependencyLine(array $info): string
    {
        if (! $info['enabled']) {
            return $info['manifest'].' · disabled (VITALWATCH_DEPENDENCIES_NPM=false)';
        }

        if ($info['packages'] === null) {
            return $info['manifest'].' not found';
        }

        return sprintf(
            '%s · %d %s · %s',
            $info['manifest'],
            $info['packages'],
            $info['packages'] === 1 ? 'package' : 'packages',
            match (true) {
                ! $info['cache'] => 'last send unknown (cache unavailable)',
                $info['sent_at'] === null => 'not sent yet',
                default => 'last sent '.self::ago(now()->getTimestamp() - $info['sent_at']),
            },
        );
    }

    private static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60).' min ago',
            $seconds < 86400 => intdiv($seconds, 3600).' h ago',
            default => intdiv($seconds, 86400).' d ago',
        };
    }

    /**
     * Bağımlılık listelerini zamanlamayı beklemeden gönderir; kilit dosyası
     * başına bir satır. `--test`'ten ayrı: test tek bir olay olarak kalır,
     * listeleri yalnızca bu seçenek yollar.
     *
     * Başarısız sayılır: bulunan ve açık bir listenin gönderimi başarısızsa
     * ya da hiçbir kilit dosyası bulunamadıysa. package-lock.json'un yokluğu
     * (pnpm/yarn/bun, saf PHP projesi) tek başına hata değil.
     */
    private function sendDependencies(): bool
    {
        if (! $this->dependenciesEnabled()) {
            $this->error('  ✗ Dependency list NOT SENT: disabled (VITALWATCH_DEPENDENCIES=false).');
            $this->newLine();

            return false;
        }

        $ok = true;
        $found = false;

        foreach (app(Recorder::class)->sendDependencies() as $manifest => $result) {
            if (! $result['enabled']) {
                $this->line(sprintf('  - %s skipped: disabled (VITALWATCH_DEPENDENCIES_NPM=false).', $manifest));

                continue;
            }

            if (! $result['found']) {
                $this->line(sprintf('  - %s not found — skipped.', $manifest));

                continue;
            }

            $found = true;

            if ($result['sent'] ?? false) {
                $this->info(sprintf(
                    '  ✓ Dependency list sent (%s, %d %s, HTTP %s).',
                    $manifest,
                    $result['packages'] ?? 0,
                    ($result['packages'] ?? 0) === 1 ? 'package' : 'packages',
                    $result['status'] ?? '?',
                ));

                continue;
            }

            $ok = false;
            $this->error(sprintf(
                '  ✗ Dependency list NOT SENT (%s): %s',
                $manifest,
                $result['error'] ?? 'HTTP '.($result['status'] ?? 'unknown'),
            ));
        }

        if (! $found) {
            $this->error('  ✗ Dependency list NOT SENT: no lock file found.');
            $ok = false;
        }

        if (! $ok) {
            $this->newLine();
        }

        return $ok;
    }

    private function sendProbe(): bool
    {
        // Gerçek bir istisna raporlanır: hem taşıma hem de exception kancası
        // aynı anda sınanmış olur.
        $result = app(Recorder::class)->recordException(
            new RuntimeException('Test event generated by vitalwatch:status --test')
        );

        if ($result['sent'] ?? false) {
            $this->info(sprintf('  ✓ Test event sent (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Test event NOT SENT: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'unknown'),
        ));
        $this->newLine();

        return false;
    }

    /**
     * Packagist'teki en yeni sürüm. Yalnızca bilgi: eski sürüm bir
     * yapılandırma hatası değil, çıkış kodu değişmez.
     *
     * `VITALWATCH_STATUS_OFFLINE=1` denetimi atlar (ağsız sunucu, CI).
     */
    private function updateCheck(): void
    {
        $offline = getenv('VITALWATCH_STATUS_OFFLINE') ?: ($_SERVER['VITALWATCH_STATUS_OFFLINE'] ?? $_ENV['VITALWATCH_STATUS_OFFLINE'] ?? '');

        if (in_array(strtolower(trim((string) $offline)), ['1', 'true', 'yes', 'on', 'evet'], true)) {
            return;
        }

        $packagist = app(Packagist::class);
        $latest = $packagist->latestStable();

        if ($latest === null) {
            $this->row('Latest version', 'could not check');

            return;
        }

        $this->row('Latest version', $latest);

        // Kurulu sürüm bilinmiyorsa ya da dev dalıysa karşılaştırılmaz.
        $installed = Packagist::stable($packagist->installed());

        if ($installed !== null && Packagist::compare($latest, $installed) > 0) {
            $this->warn('  ! Update available: composer require cestnytr/vitalwatch-laravel:'.Packagist::constraint($latest));
        }
    }

    private function row(string $label, string $value): void
    {
        // sprintf bayt sayıyor; Türkçe harfli etiketler kayıyordu.
        $this->line('  '.$label.str_repeat(' ', max(1, 19 - mb_strlen($label))).$value);
    }
}
