<?php

use CestnyTR\VitalWatch\Support\Dependencies;

/*
| Sözleşme dört SDK'da aynı (v1): yalnızca üretim paketleri, `v` atılır,
| dal sürümleri atlanır, ada göre sıralı, en çok 5000, özet sıralı
| `ad@sürüm\n` satırlarının sha256'sı.
*/

function lockJson(array $packages, array $dev = []): string
{
    return json_encode(['content-hash' => 'x', 'packages' => $packages, 'packages-dev' => $dev]);
}

test('kanonik özet sıralı ad@sürüm satırlarının sha256 değeri', function () {
    $list = Dependencies::parse(lockJson([
        ['name' => 'symfony/console', 'version' => 'v7.1.0'],
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
    ]));

    expect($list['packages'])->toBe([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
        ['name' => 'symfony/console', 'version' => '7.1.0'],
    ])
        ->and($list['hash'])->toBe(hash('sha256', "guzzlehttp/guzzle@7.8.1\nsymfony/console@7.1.0\n"));
});

test('özet paket sırasından bağımsız', function () {
    $a = Dependencies::parse(lockJson([
        ['name' => 'b/b', 'version' => '1.0.0'],
        ['name' => 'a/a', 'version' => '2.0.0'],
    ]));
    $b = Dependencies::parse(lockJson([
        ['name' => 'a/a', 'version' => '2.0.0'],
        ['name' => 'b/b', 'version' => '1.0.0'],
    ]));

    expect($a['hash'])->toBe($b['hash']);
});

test('yalnızca üretim paketleri okunur, packages-dev okunmaz', function () {
    $list = Dependencies::parse(lockJson(
        [['name' => 'laravel/framework', 'version' => 'v12.0.0']],
        [['name' => 'pestphp/pest', 'version' => 'v3.0.0']],
    ));

    expect(array_column($list['packages'], 'name'))->toBe(['laravel/framework']);
});

test('sürümün başındaki v atılır', function ($input, $expected) {
    expect(Dependencies::normalizeVersion($input))->toBe($expected);
})->with([
    ['v7.8.1', '7.8.1'],
    ['V1.2.3', '1.2.3'],
    ['7.8.1', '7.8.1'],
    // `v` rakamdan önce değilse dokunulmaz.
    ['vendor-1', 'vendor-1'],
]);

test('dal sürümleri atlanır', function ($input) {
    expect(Dependencies::normalizeVersion($input))->toBeNull();
})->with(['dev-main', 'dev-master', '2.x-dev', '1.0.x-DEV', '', null, 42]);

test('dal sürümlü paket listeye girmez', function () {
    $list = Dependencies::parse(lockJson([
        ['name' => 'acme/fork', 'version' => 'dev-main'],
        ['name' => 'acme/stable', 'version' => '1.0.0'],
    ]));

    expect(array_column($list['packages'], 'name'))->toBe(['acme/stable']);
});

test('en çok 5000 paket gönderilir', function () {
    $packages = [];

    for ($i = 0; $i < 6000; $i++) {
        $packages[] = ['name' => sprintf('vendor/p%05d', $i), 'version' => '1.0.0'];
    }

    $list = Dependencies::parse(lockJson($packages));

    expect($list['packages'])->toHaveCount(Dependencies::MAX_PACKAGES)
        ->and($list['packages'][4999]['name'])->toBe('vendor/p04999');
});

test('bozuk kilit dosyası null döner', function () {
    expect(Dependencies::parse('{not json'))->toBeNull()
        ->and(Dependencies::parse('{"foo": 1}'))->toBeNull();
});

test('kilit dosyası yoksa hiçbir şey okunmaz', function () {
    $path = sys_get_temp_dir().'/vitalwatch-missing-'.uniqid().'/composer.lock';

    expect(Dependencies::fingerprint($path))->toBeNull()
        ->and(Dependencies::load($path))->toBeNull();
});

test('ayrıştırma sonucu mtime ve boyuta göre önbelleklenir', function () {
    Dependencies::forget();
    $path = tempnam(sys_get_temp_dir(), 'vw-lock');
    file_put_contents($path, lockJson([['name' => 'a/a', 'version' => '1.0.0']]));
    touch($path, 1_700_000_000);

    $first = Dependencies::load($path);

    // Aynı boyut, aynı mtime: içerik değişse de yeniden okunmaz.
    file_put_contents($path, lockJson([['name' => 'b/b', 'version' => '1.0.0']]));
    touch($path, 1_700_000_000);
    expect(Dependencies::load($path))->toBe($first);

    // mtime değişti (deploy): yeniden okunur.
    touch($path, 1_700_000_100);
    expect(Dependencies::load($path)['packages'][0]['name'])->toBe('b/b');

    @unlink($path);
    Dependencies::forget();
});

test('gönderim günde bir ve liste değişince', function () {
    $now = 1_700_000_000;

    expect(Dependencies::due(null, 'h1', $now))->toBeTrue()
        ->and(Dependencies::due(['hash' => 'h1', 'sent_at' => $now - 3600], 'h1', $now))->toBeFalse()
        ->and(Dependencies::due(['hash' => 'h1', 'sent_at' => $now - 3600], 'h2', $now))->toBeTrue()
        ->and(Dependencies::due(['hash' => 'h1', 'sent_at' => $now - 86400], 'h1', $now))->toBeTrue();
});

test('gövde sözleşmesi', function () {
    $payload = Dependencies::payload(['hash' => 'abc', 'packages' => [['name' => 'a/a', 'version' => '1.0.0']]]);

    expect($payload)->toBe([
        'v' => 1,
        'ecosystem' => 'Packagist',
        'manifest' => 'composer.lock',
        'hash' => 'abc',
        'packages' => [['name' => 'a/a', 'version' => '1.0.0']],
    ]);
});
