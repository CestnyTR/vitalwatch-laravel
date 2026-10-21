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
