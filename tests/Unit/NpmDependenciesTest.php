<?php

use CestnyTR\VitalWatch\Support\Dependencies;
use CestnyTR\VitalWatch\Support\ManifestSource;
use CestnyTR\VitalWatch\Support\NpmDependencies;

/*
| package-lock.json ayrıştırması Node SDK'nın (vitalwatch-node
| src/dependencies.js) birebir karşılığı. Beklenen özetler Node
| uygulamasıyla aynı fikstür üzerinde hesaplandı:
|
|   node -e 'const d=require("./src/dependencies.js"); ...hashPackages(parseLockfile(V3))'
*/

/** Node testindeki V3 fikstürü + `zod` ve büyük harfli `B`. */
function npmV3(): array
{
    return [
        'name' => 'app',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'app', 'version' => '1.0.0'],
            'node_modules/next' => ['version' => '16.1.6'],
            'node_modules/react' => ['version' => '19.0.0'],
            'node_modules/eslint' => ['version' => '9.0.0', 'dev' => true],
            'node_modules/local-lib' => ['resolved' => '../lib', 'link' => true],
            'node_modules/next/node_modules/react' => ['version' => '18.3.1'],
            'node_modules/@scope/pkg' => ['version' => '2.0.0'],
            'node_modules/a/node_modules/@scope/pkg' => ['version' => '2.0.0'],
            'node_modules/no-version' => new stdClass,
            'node_modules/opt' => ['version' => '1.0.0', 'optional' => true],
            'node_modules/dev-opt' => ['version' => '1.1.0', 'devOptional' => true],
            'node_modules/alias' => ['name' => 'real-pkg', 'version' => '3.0.0'],
            'packages/workspace-a' => ['name' => 'workspace-a', 'version' => '0.0.1'],
            'node_modules/zod' => ['version' => '3.22.2'],
            'node_modules/B' => ['version' => '1.0.0'],
        ],
    ];
}

function npmV1(): array
{
    return [
        'lockfileVersion' => 1,
        'dependencies' => [
            'express' => [
                'version' => '4.17.1',
                'dependencies' => ['qs' => ['version' => '6.7.0'], 'debug' => ['version' => '2.6.9']],
            ],
            'qs' => ['version' => '6.11.0'],
            'debug' => ['version' => '2.6.9'],
            'mocha' => ['version' => '10.0.0', 'dev' => true, 'dependencies' => ['chai' => ['version' => '4.0.0']]],
            'local' => ['version' => 'file:../local'],
        ],
    ];
}

test('v3: iç içe node_modules, takma ad, dev/link/sürümsüz/workspace atlanır, tekilleşir', function () {
    $list = NpmDependencies::parse(json_encode(npmV3()));

    expect($list['packages'])->toBe([
        ['name' => '@scope/pkg', 'version' => '2.0.0'],
        // Bayt sırası: büyük harf önce (Node ile aynı, yerel ayara bağlı değil).
        ['name' => 'B', 'version' => '1.0.0'],
        ['name' => 'dev-opt', 'version' => '1.1.0'],
        ['name' => 'next', 'version' => '16.1.6'],
        ['name' => 'opt', 'version' => '1.0.0'],
        ['name' => 'react', 'version' => '18.3.1'],
        ['name' => 'react', 'version' => '19.0.0'],
        ['name' => 'real-pkg', 'version' => '3.0.0'],
        ['name' => 'zod', 'version' => '3.22.2'],
    ]);
});

test('v3 özeti Node SDK ile aynı', function () {
    expect(NpmDependencies::parse(json_encode(npmV3()))['hash'])
        ->toBe('c6b1478b58709baf0ee9392c4ac0c386c2645d4613e16fc381899068e9094596');
});

test('anahtar sırası özeti değiştirmez', function () {
    $lock = npmV3();
    $lock['packages'] = array_reverse($lock['packages'], true);

    expect(NpmDependencies::parse(json_encode($lock))['hash'])
        ->toBe('c6b1478b58709baf0ee9392c4ac0c386c2645d4613e16fc381899068e9094596');
});

test('v2: packages varsa eski dependencies ağacı yok sayılır', function () {
    $lock = [
        'lockfileVersion' => 2,
        'packages' => ['' => new stdClass, 'node_modules/express' => ['version' => '4.21.0']],
        'dependencies' => ['express' => ['version' => '4.21.0'], 'ignored' => ['version' => '1.0.0']],
    ];

    expect(NpmDependencies::parse(json_encode($lock))['packages'])
        ->toBe([['name' => 'express', 'version' => '4.21.0']]);
});

test('v1: dependencies ağacı özyinelemeli, dev ve file: atlanır; özet Node ile aynı', function () {
    $list = NpmDependencies::parse(json_encode(npmV1()));

    expect($list['packages'])->toBe([
        ['name' => 'debug', 'version' => '2.6.9'],
        ['name' => 'express', 'version' => '4.17.1'],
        ['name' => 'qs', 'version' => '6.11.0'],
        ['name' => 'qs', 'version' => '6.7.0'],
    ])->and($list['hash'])->toBe('0d4dbc31d05d6abcc7d2e7f936acfcf51660b2801c96fab8cb98561c645e858f');
});

test('bozuk girdide boş liste, fırlatmaz', function ($input) {
    expect(NpmDependencies::packages($input))->toBe([]);
})->with([
    'null' => [null],
    'sayı' => [42],
    'dizge' => ['x'],
    'boş' => [[]],
    'packages null' => [['packages' => null]],
    'girdi dizge' => [['packages' => ['node_modules/a' => 'x']]],
    'sürüm sayı' => [['packages' => ['node_modules/a' => ['version' => 1]]]],
]);

test('geçersiz JSON null döner', function () {
    expect(NpmDependencies::parse('{bozuk'))->toBeNull()
        ->and(NpmDependencies::parse('42'))->toBeNull();
});

test('en çok 5000 paket, sınır sıralamadan sonra; özet kesilmiş liste üzerinden', function () {
    $packages = [];

    for ($i = Dependencies::MAX_PACKAGES + 249; $i >= 0; $i--) {
        $packages[sprintf('node_modules/p%05d', $i)] = ['version' => '1.0.0'];
    }

    $list = NpmDependencies::parse(json_encode(['lockfileVersion' => 3, 'packages' => $packages]));

    expect($list['packages'])->toHaveCount(5000)
        ->and($list['packages'][4999]['name'])->toBe('p04999')
        ->and($list['hash'])->toBe(Dependencies::hash($list['packages']));
});

test('ayrıştırma yol + mtime + boyuta göre önbelleklenir; composer ile npm birbirini silmez', function () {
    Dependencies::forget();
    $npm = tempnam(sys_get_temp_dir(), 'vw-npm');
    $composer = tempnam(sys_get_temp_dir(), 'vw-lock');
    writeNpmLock($npm, ['node_modules/a' => '1.0.0']);
    file_put_contents($composer, json_encode(['packages' => [['name' => 'a/a', 'version' => '1.0.0']]]));
    touch($npm, 1_700_000_000);

    $first = NpmDependencies::load($npm);
    Dependencies::load($composer);

    // Aynı boyut ve mtime: içerik değişse de yeniden okunmaz (composer okuması önbelleği silmedi).
    writeNpmLock($npm, ['node_modules/b' => '1.0.0']);
    touch($npm, 1_700_000_000);
    expect(NpmDependencies::load($npm))->toBe($first);

    touch($npm, 1_700_000_100);
    expect(NpmDependencies::load($npm)['packages'][0]['name'])->toBe('b');

    @unlink($npm);
    @unlink($composer);
    Dependencies::forget();
});

test('npm kaynağının gövdesi', function () {
    $payload = ManifestSource::npm('/x/package-lock.json')
        ->payload(['hash' => 'abc', 'packages' => [['name' => 'zod', 'version' => '3.22.2']]]);

    expect($payload)->toBe([
        'v' => 1,
        'ecosystem' => 'npm',
        'manifest' => 'package-lock.json',
        'hash' => 'abc',
        'packages' => [['name' => 'zod', 'version' => '3.22.2']],
    ]);
});
