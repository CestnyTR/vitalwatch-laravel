<?php

use CestnyTR\VitalWatch\Support\Scrubber;

test('kişisel veri desenleri maskelenir', function (string $input, string $expected) {
    expect(Scrubber::text($input, 500))->toBe($expected);
})->with([
    'email' => ['Kullanıcı ahmet@ornek.com bulunamadı', 'Kullanıcı [email] bulunamadı'],
    'TC kimlik' => ['TCKN 12345678901 geçersiz', 'TCKN [national_id] geçersiz'],
    'IBAN' => ['TR330006100519786457841326 reddedildi', '[iban] reddedildi'],
    'card' => ['4111 1111 1111 1111 hatalı', '[card] hatalı'],
    'phone' => ['Ara: 0555 123 45 67', 'Ara: [phone]'],
]);

test('query string atılır, yol kalır', function () {
    expect(Scrubber::path('/urunler?email=x@y.com&token=abc'))->toBe('/urunler')
        ->and(Scrubber::path('https://example.com.tr/urunler?q=1'))->toBe('/urunler');
});

test('SQL literal değerleri normalize edilir', function () {
    expect(Scrubber::sql("select * from users where email = 'john@sample.com'"))
        ->toBe('select * from users where email = ?');

    expect(Scrubber::sql('select * from orders where user_id = 42'))
        ->toBe('select * from orders where user_id = ?');

    expect(Scrubber::sql('select * from t where id in (1, 2, 3)'))
        ->toBe('select * from t where id in (?)');
});

test('normalize edilmiş SQL kişisel veri içermez', function () {
    $result = Scrubber::sql("insert into users (email, tc) values ('a@b.com', '12345678901')");

    expect($result)->not->toContain('a@b.com')
        ->and($result)->not->toContain('12345678901');
});

test('stack trace 2000 karakterde kesilir', function () {
    // Gerçekçi bir iz: tek harften oluşan blok jeton desenine takılırdı.
    $reveal = str_repeat("#0 /app/vendor/laravel/framework/src/Foo.php(42): bar()\n", 200);

    expect(Scrubber::stack($reveal))->toHaveLength(2000);
});

test('boş değerler null döner', function () {
    expect(Scrubber::text(null, 500))->toBeNull()
        ->and(Scrubber::path(''))->toBeNull()
        ->and(Scrubber::sql(null))->toBeNull();
});

/**
 * Gerçek bir sızıntıda yakalandı: QueryException'ın mesajı SQL'i bağlanmış
 * değerlerle taşıyor ve içinde oturum kimliği vardı.
 */
test('SQL taşıyan exception mesajından oturum kimliği sızmaz', function () {
    $message = 'Database error (Connection: sqlite, SQL: select * from "sessions" '
        .'where "id" = ItLsnnji2VLJtiE1SjiyAEdzv6aZiuVqhFvcmRu1 limit 1)';

    $result = Scrubber::message($message, true);

    // SQL normalizasyonu değeri `?` ile değiştiriyor; jeton desenine
    // kalmadan temizleniyor. Önemli olan kimliğin çıkmaması.
    expect($result)->not->toContain('ItLsnnji2VLJtiE1SjiyAEdzv6aZiuVqhFvcmRu1')
        ->and($result)->toContain('"?" = ?');
});

test('uzun rastgele diziler her mesajda maskelenir', function () {
    // Fixture bilinçli olarak hiçbir sağlayıcının anahtar biçimine benzemiyor:
    // gerçekçi bir sağlayıcı öneki kullanmak GitHub'ın gizli anahtar
    // taramasına takılıp push'u engelliyordu.
    expect(Scrubber::text('Token: ornek_jeton_ABCDEFGHIJKLMNOPQRSTUVWXYZ', 500))
        ->toContain('[token]');
});

test('normal kelimeler ve sınıf adları maskelenmez', function () {
    $message = 'Illuminate\\Database\\QueryException on ProductController@show';

    expect(Scrubber::text($message, 500))->toBe($message);
});

test('SQL literal değerleri mesajda da normalize edilir', function () {
    $result = Scrubber::message("SQL: insert into users (email) values ('a@b.com')", true);

    expect($result)->not->toContain('a@b.com');
});

/*
| Yükleme dosya adları okunur kalmalı: zaman damgası kart, uzun ad jeton
| sanılıyordu (`/uploads/discount/[token][card]-752066249.jpeg`).
| Beklenenler hub Scrubber'ıyla ortak vektörlerden.
*/
test('yükleme dosya adı okunur kalır', function (string $path) {
    expect(Scrubber::path($path))->toBe($path);
})->with([
    ['/uploads/discount/kampanya-gorseli-yaz-indirimi-1726571234567-752066249.jpeg'],
    ['/uploads/sliders/slider-slider-1726571234567-249230604-1726571239999-875783343.png'],
    ['/uploads/products/urun-1795123456789-123456789.png'],
]);

test('kart yalnızca gerçek kart numarasıysa maskelenir', function (string $input, string $expected) {
    expect(Scrubber::text($input, 500))->toBe($expected);
})->with([
    ['kart 4111111111111111 red', 'kart [card] red'],
    ['378282246310005 red', '[card] red'],
    ['saat 1726571234567 geçti', 'saat 1726571234567 geçti'],
    ['no 4111111111111112', 'no 4111111111111112'],
    // Luhn'u tutan damga: yalnızca ilk hane kuralı ayırıyor.
    ['saat 1726571234573 geçti', 'saat 1726571234573 geçti'],
]);

// Sol sınır eklenince + olmadan 90 ön eki sızıyordu (önceden `9[phone]`).
test('90 ön ekli telefon maskelenir', function (string $input) {
    expect(Scrubber::text($input, 500))->toBe('tel [phone]');
})->with([['tel 905321234567'], ['tel 90 532 123 45 67']]);

test('rastgele diziler yeni kuralla da maskelenir', function (string $input) {
    expect(Scrubber::text($input, 500))->toBe('x [token] y');
})->with([
    ['x 3f2a1b4c-5d6e-4f70-8a9b-0c1d2e3f4a5b y'],
    ['x ghp_16C7e42F292c6912E7710c838347Ae178B4a y'],
    ['x a1b2c3d4-e5f6a7b8-c9d0e1f2-a3b4c5d6 y'],
]);

// Eposta deseni `/u` bayraklı; geçersiz baytta desen sessizce atlanıyordu.
test('geçersiz UTF-8 taşıyan metinde eposta yine maskelenir', function () {
    expect(Scrubber::message("SQLSTATE: ahmet@ornek.com \xff", true))->toBe('SQLSTATE: [email] ?');
});
