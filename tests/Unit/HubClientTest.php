<?php

use CestnyTR\VitalWatch\Transport\HubClient;

/*
| VITALWATCH_TIMEOUT: Node paketi aynı adla milisaniye bekliyor. `2000` yazan
| birinin PHP sürecini hub'a 2000 saniye bağlaması uygulamayı kilitlerdi;
| `(int)` okuma da 0.5'i sıfıra, curl'de sınırsız beklemeye çeviriyordu.
*/
test('zaman aşımı saniye, 100 ve üstü milisaniye, 0,1–10 sn aralığında', function ($input, float $expected) {
    expect(HubClient::timeoutSeconds($input))->toBe($expected);
})->with([
    [2, 2.0],
    ['2', 2.0],
    ['0.5', 0.5],
    [2000, 2.0],
    ['1500', 1.5],
    ['0', 2.0],
    ['abc', 2.0],
    [null, 2.0],
    ['-5', 2.0],
    ['1e3', 1.0],
    // Kırpma: 0 ms curl'de sınırsız bekleme demek.
    ['0.0001', 0.1],
    ['1e400', 2.0],
    ['50', 10.0],
    [60000, 10.0],
]);

test('geçersiz UTF-8 olayı düşürmez', function () {
    $body = HubClient::encode(['kind' => 'exception', 'release' => "v1\xff"]);

    expect($body)->not->toBeNull()
        ->and(json_decode($body, true)['release'])->toBe("v1\u{FFFD}");
});

/*
| Hub 8 KB'tan büyük gövdeyi okumadan atıyor ve yine 204 dönüyor.
| Karakterle kesilen alanlar baytta sınırı aşabiliyordu.
*/
test('sınırı aşan gövde stack atılarak sığdırılır', function () {
    $body = HubClient::encode([
        'kind' => 'exception',
        'msg' => str_repeat('ş', 500),
        // 2000 karakter, 4 baytlık karakterlerle ~8 KB. Gerçekçi Türkçe
        // stack ~4 KB'ta kalıyor; sınır nadir girdide aşılıyor.
        'stack' => str_repeat('😀', 2000),
        'exception_class' => 'RuntimeException',
    ]);

    $event = json_decode($body, true);

    expect(strlen($body))->toBeLessThanOrEqual(HubClient::MAX_BODY_BYTES)
        ->and($event)->not->toHaveKey('stack')
        ->and($event['exception_class'])->toBe('RuntimeException')
        ->and(mb_strlen($event['msg']))->toBe(500);
});

test('sığan gövde olduğu gibi gönderilir', function () {
    $event = ['kind' => 'exception', 'msg' => 'kısa', 'stack' => '#0 a.php'];

    expect(json_decode(HubClient::encode($event), true))->toBe($event);
});

test('stack atılınca da sığmazsa mesaj kısaltılır', function () {
    $body = HubClient::encode([
        'kind' => 'exception',
        'msg' => str_repeat('ğ', 5000),
        'stack' => 'x',
    ]);

    expect(strlen($body))->toBeLessThanOrEqual(HubClient::MAX_BODY_BYTES)
        ->and(mb_strlen(json_decode($body, true)['msg']))->toBe(200);
});

/** Ağa çıkmadan imzalı isteği yakalayan istemci. */
function capturingClient(): HubClient
{
    return new class extends HubClient
    {
        public array $requests = [];

        public function __construct()
        {
            parent::__construct('https://hub.example.test/', 'project-key', str_repeat('s', 64), 1);
        }

        protected function transmit(string $url, array $headers, string $body): array
        {
            $this->requests[] = compact('url', 'headers', 'body');

            return ['sent' => true, 'status' => 204];
        }
    };
}

/** @return array<string, string> */
function headerMap(array $headers): array
{
    $map = [];

    foreach ($headers as $line) {
        [$name, $value] = explode(': ', $line, 2);
        $map[$name] = $value;
    }

    return $map;
}

/*
| Bağımlılık ucu ayrı bir imza yolu açmıyor: /server ile aynı başlıklar,
| aynı HMAC (zaman damgası + "." + gövde).
*/
test('bağımlılık listesi /deps ucuna olaylarla aynı imzayla gider', function () {
    $client = capturingClient();

    $client->send(['events' => []]);
    $client->sendDependencies(['env' => 'production', 'dependencies' => ['v' => 1, 'packages' => []]]);

    [$server, $deps] = $client->requests;

    expect($server['url'])->toBe('https://hub.example.test/api/i/project-key/server')
        ->and($deps['url'])->toBe('https://hub.example.test/api/i/project-key/deps')
        ->and(array_keys(headerMap($deps['headers'])))->toBe(array_keys(headerMap($server['headers'])));

    foreach ([$server, $deps] as $request) {
        $h = headerMap($request['headers']);

        expect($h['Content-Type'])->toBe('application/json')
            ->and($h['X-VitalWatch-Signature'])
            ->toBe('sha256='.hash_hmac('sha256', $h['X-VitalWatch-Timestamp'].'.'.$request['body'], str_repeat('s', 64)));
    }
});

test('imza başlıkları belirli zaman damgasıyla tekrarlanabilir', function () {
    $headers = headerMap(capturingClient()->headers('{"a":1}', '1700000000'));

    expect($headers['X-VitalWatch-Timestamp'])->toBe('1700000000')
        ->and($headers['X-VitalWatch-Signature'])->toBe('sha256='.hash_hmac('sha256', '1700000000.{"a":1}', str_repeat('s', 64)));
});

test('bağımlılık gövdesi 8 KB sınırına takılmaz, 512 KB üstü gönderilmez', function () {
    $client = capturingClient();

    $packages = array_map(fn ($i) => ['name' => "vendor/package-{$i}", 'version' => '1.0.0'], range(1, 1000));
    $result = $client->sendDependencies(['dependencies' => ['packages' => $packages]]);

    expect($result['sent'])->toBeTrue()
        ->and(strlen($client->requests[0]['body']))->toBeGreaterThan(HubClient::MAX_BODY_BYTES);

    $huge = $client->sendDependencies(['dependencies' => ['blob' => str_repeat('x', HubClient::MAX_DEPS_BODY_BYTES)]]);

    expect($huge)->toBe(['sent' => false, 'error' => 'body-too-large'])
        ->and($client->requests)->toHaveCount(1);
});

test('yapılandırma eksikse bağımlılık listesi gönderilmez', function () {
    expect((new HubClient(null, null, null, 1))->sendDependencies(['dependencies' => []]))
        ->toBe(['sent' => false, 'error' => 'missing-config']);
});
