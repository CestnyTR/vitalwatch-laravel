<?php

namespace CestnyTR\VitalWatch\Console;

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Support\Environment;
use CestnyTR\VitalWatch\Support\Packagist;
use CestnyTR\VitalWatch\Support\Release;
use Illuminate\Console\Command;
use RuntimeException;

/**
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
                            {--test : Hub\'a sınama olayı gönderir — panelde hata olarak görünür}
                            {--vitalwatch : Yalnızca canlılık isteği gönderir — panele hata düşürmez}';

    protected $description = 'VitalWatch kurulumunu denetler';

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
        $this->row('Paket sürümü', Recorder::version());
        $this->updateCheck();
        $this->row('Etkin', config('vitalwatch.enabled') ? 'evet' : 'HAYIR (VITALWATCH_ENABLED=false)');
        $this->row('Hub adresi', (string) config('vitalwatch.url') ?: 'TANIMSIZ');
        $this->row('Proje anahtarı', (string) config('vitalwatch.key') ?: 'TANIMSIZ');
        $this->row('Secret uzunluğu', $secret === '' ? 'TANIMSIZ' : strlen($secret).' karakter');
        $envRaw = (string) (config('vitalwatch.env') ?: app()->environment());
        $envName = Environment::normalize($envRaw);
        $this->row('Environment', $envName === $envRaw ? $envName : "{$envName} ({$envRaw})");
        $release = Release::detect(base_path(), config('vitalwatch.release'));
        $this->row('Sürüm etiketi', sprintf('%s (kaynak: %s)', $release['value'] ?? 'tanımsız', $release['source']));
        $this->newLine();

        $problems = $this->problems($secret);

        foreach ($problems as $problem) {
            $this->error('  ✗ '.$problem);
        }

        if ($problems !== []) {
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  ✓ Yapılandırma tamam.');

        if ($this->option('test') && ! $this->sendProbe()) {
            return self::FAILURE;
        }

        if ($this->option('vitalwatch') && ! $this->sendHeartbeat()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Verinin gerçekten ulaştığı yalnızca hub panelinden doğrulanır:');
        $this->line('  proje satırında bağlantı durumu "Bağlı" görünmelidir.');
        $this->line('  Hub geçersiz imzaya da 204 döner; buradan anlaşılmaz.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function problems(string $secret): array
    {
        $problems = [];

        if (! config('vitalwatch.enabled')) {
            $problems[] = 'VITALWATCH_ENABLED=false — hiçbir kanca kurulmaz, hiçbir veri gönderilmez.';
        }

        $envRaw = (string) (config('vitalwatch.env') ?: app()->environment());

        if (! Environment::accepted(Environment::normalize($envRaw))) {
            $problems[] = "Ortam \"{$envRaw}\" hub tarafından kabul edilmez — olaylar ve canlılık sessizce reddedilir. VITALWATCH_ENV=production, staging ya da local yazın.";
        }

        $missing = collect(['VITALWATCH_URL' => 'url', 'VITALWATCH_KEY' => 'key', 'VITALWATCH_SECRET' => 'secret'])
            ->filter(fn (string $key) => empty(config("vitalwatch.{$key}")))
            ->keys();

        if ($missing->isNotEmpty()) {
            $problems[] = $missing->implode(', ').' tanımlı değil.';
        }

        if ($secret !== '' && strlen($secret) !== self::SECRET_LENGTH) {
            // En sık hata bu: secret kopyalanırken başı veya sonu eksik
            // kalıyor ve sonuç sessizce hiçbir şey göndermemek oluyor.
            $problems[] = sprintf(
                'VITALWATCH_SECRET %d karakter olmalı, %d karakter. Eksik kopyalanmış olabilir.',
                self::SECRET_LENGTH,
                strlen($secret),
            );
        }

        if (str_starts_with((string) config('vitalwatch.url'), 'http://')) {
            $problems[] = 'VITALWATCH_URL http:// ile başlıyor — secret imzası şifresiz hat üzerinden gider.';
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
            $this->info(sprintf('  ✓ Canlılık isteği gönderildi (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Canlılık isteği GÖNDERİLEMEDİ: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'isUnknown'),
        ));
        $this->newLine();

        return false;
    }

    private function sendProbe(): bool
    {
        // Gerçek bir istisna raporlanır: hem taşıma hem de exception kancası
        // aynı anda sınanmış olur.
        $result = app(Recorder::class)->recordException(
            new RuntimeException('vitalwatch:status --test ile üretilen sınama olayı')
        );

        if ($result['sent'] ?? false) {
            $this->info(sprintf('  ✓ Sınama olayı gönderildi (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Sınama olayı GÖNDERİLEMEDİ: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'isUnknown'),
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
            $this->row('Güncel sürüm', 'denetlenemedi');

            return;
        }

        $this->row('Güncel sürüm', $latest);

        // Kurulu sürüm bilinmiyorsa ya da dev dalıysa karşılaştırılmaz.
        $installed = Packagist::stable($packagist->installed());

        if ($installed !== null && Packagist::compare($latest, $installed) > 0) {
            $this->warn('  ! Güncelleme var: composer require cestnytr/vitalwatch-laravel:'.Packagist::constraint($latest));
        }
    }

    private function row(string $label, string $value): void
    {
        // sprintf bayt sayıyor; Türkçe harfli etiketler kayıyordu.
        $this->line('  '.$label.str_repeat(' ', max(1, 19 - mb_strlen($label))).$value);
    }
}
