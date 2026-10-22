<?php

use CestnyTR\VitalWatch\Recorder;
use CestnyTR\VitalWatch\Transport\HubClient;

/**
 * Bellek tükenmesi ve zaman aşımı PHP'nin istisna mekanizmasından geçmez:
 * süreç ölür, exception handler hiç çalışmaz. Siteyi **gerçekten düşüren**
 * hata sınıfı bu ve bugüne kadar panele hiç düşmüyordu.
 */
function fatalKaydedici(array &$gonderilenler): Recorder
{
    $client = new class($gonderilenler) extends HubClient
    {
        public function __construct(public array &$gonderilenler)
        {
            parent::__construct('https://x.test', 'example-project', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    return new Recorder($client, [
        'key' => 'example-project', 'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);
}

test('ölümcül hata fatal türüyle gönderilir', function () {
    $gonderilenler = [];

    fatalKaydedici($gonderilenler)->recordFatal([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 134217728 bytes exhausted',
        'file' => '/var/www/app/Http/Controllers/RaporController.php',
        'line' => 88,
    ]);

    $event = $gonderilenler[0];

    // exception DEĞİL: bir exception uygulamanın yakalayabildiği bir şey,
    // fatal ise sunucunun sınırına çarpması. Ayrıca süzülebilmeli.
    expect($event['kind'])->toBe('fatal')
        ->and($event['msg'])->toContain('Fatal error')
        ->and($event['msg'])->toContain('memory size')
        ->and($event['line'])->toBe(88)
        // Limite mi çarpıldı, başka sebeple mi ölündü — ayrımı bu veriyor.
        ->and($event)->toHaveKey('memory_mb');
});

test('hata türü okunur adıyla yazılır', function () {
    $gonderilenler = [];
    $recorder = fatalKaydedici($gonderilenler);

    $recorder->recordFatal(['type' => E_PARSE, 'message' => 'x', 'file' => '/a.php', 'line' => 1]);
    $recorder->recordFatal(['type' => E_COMPILE_ERROR, 'message' => 'y', 'file' => '/b.php', 'line' => 2]);

    expect($gonderilenler[0]['msg'])->toContain('Parse error')
        ->and($gonderilenler[1]['msg'])->toContain('Compile error');
});

/**
 * Bellek hatası dosya yollarını, zaman aşımı çalışan sorguyu taşıyabiliyor;
 * ikisi de temizlikten geçmeli (M8).
 */
test('ölümcül hata mesajı maskelemeden geçer', function () {
    $gonderilenler = [];

    fatalKaydedici($gonderilenler)->recordFatal([
        'type' => E_ERROR,
        'message' => "Maximum execution time exceeded, query: select * from users where email = 'attempt@sample.com'",
        'file' => '/var/www/x.php',
        'line' => 5,
    ]);

    expect($gonderilenler[0]['msg'])->not->toContain('attempt@sample.com');
});
