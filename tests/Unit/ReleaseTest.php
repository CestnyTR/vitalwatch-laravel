<?php

use CestnyTR\VitalWatch\Support\Release;

/*
| Sürüm etiketi: VITALWATCH_RELEASE → CI/PaaS değişkeni → `.git` → yok.
| Kural Node ve Python paketleriyle aynı.
*/

const VITALWATCH_SHA = 'ABCDEF0123456789abcdef0123456789abcdef01';

function vitalwatchTempDir(): string
{
    $dir = sys_get_temp_dir().'/vitalwatch-release-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function vitalwatchWrite(string $path, string $content): void
{
    is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

function vitalwatchRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

function vitalwatchClearEnv(): void
{
    foreach (Release::ENV_VARS as $name) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }
}

beforeEach(function () {
    vitalwatchClearEnv();
    $this->dir = vitalwatchTempDir();
});

afterEach(function () {
    vitalwatchClearEnv();
    vitalwatchRemove($this->dir);
});

test('dal referansı okunur', function () {
    vitalwatchWrite("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
    vitalwatchWrite("{$this->dir}/.git/refs/heads/main", VITALWATCH_SHA."\n");

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => '.git']);
});

test('gevşek referans yoksa packed-refs okunur', function () {
    vitalwatchWrite("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
    vitalwatchWrite("{$this->dir}/.git/packed-refs", implode("\n", [
        '# pack-refs with: peeled fully-peeled sorted',
        str_repeat('1', 40).' refs/heads/eski',
        VITALWATCH_SHA.' refs/heads/main',
        '^'.str_repeat('2', 40),
        '',
    ]));

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => '.git']);
});

test('ayrık HEAD doğrudan commit', function () {
    vitalwatchWrite("{$this->dir}/.git/HEAD", VITALWATCH_SHA."\n");

    expect(Release::detect($this->dir)['value'])->toBe('abcdef012345');
});

test('.git dosyası gitdir yolunu gösterir (göreli)', function () {
    $actual = dirname($this->dir).'/'.basename($this->dir).'-actual';
    vitalwatchWrite("{$this->dir}/.git", 'gitdir: ../'.basename($actual)."\n");
    vitalwatchWrite("{$actual}/HEAD", "ref: refs/heads/main\n");
    vitalwatchWrite("{$actual}/refs/heads/main", VITALWATCH_SHA);

    try {
        expect(Release::detect($this->dir)['value'])->toBe('abcdef012345');
    } finally {
        vitalwatchRemove($actual);
    }
});

test('.git dosyası mutlak gitdir yolunu gösterir', function () {
    vitalwatchWrite("{$this->dir}/modules/app/HEAD", VITALWATCH_SHA);
    vitalwatchWrite("{$this->dir}/project/.git", "gitdir: {$this->dir}/modules/app\n");

    expect(Release::detect("{$this->dir}/project")['value'])->toBe('abcdef012345');
});

test('bozuk ya da eksik .git hiçbir şey döndürmez, fırlatmaz', function (callable $hazirla) {
    $hazirla($this->dir);

    expect(Release::detect($this->dir))->toBe(['value' => null, 'source' => 'none']);
})->with([
    'git yok' => [fn ($d) => null],
    'çöp HEAD' => [fn ($d) => vitalwatchWrite("{$d}/.git/HEAD", "merhaba dünya\n")],
    'kısa sha' => [fn ($d) => vitalwatchWrite("{$d}/.git/HEAD", "abcdef0\n")],
    'dal yok, packed-refs yok' => [fn ($d) => vitalwatchWrite("{$d}/.git/HEAD", "ref: refs/heads/main\n")],
    'dal dosyası çöp' => [function ($d) {
        vitalwatchWrite("{$d}/.git/HEAD", "ref: refs/heads/main\n");
        vitalwatchWrite("{$d}/.git/refs/heads/main", 'xyz');
    }],
    'depo dışına çıkan ref' => [function ($d) {
        vitalwatchWrite("{$d}/.git/HEAD", "ref: refs/../../disari\n");
        // refs/ var: yol gerçekten çözülebilir, yalnızca `..` denetimi durdurur.
        mkdir("{$d}/.git/refs");
        vitalwatchWrite("{$d}/outside", VITALWATCH_SHA);
    }],
    'gitdir çöp' => [fn ($d) => vitalwatchWrite("{$d}/.git", 'bu bir dizin değil')],
    'gitdir olmayan yer' => [fn ($d) => vitalwatchWrite("{$d}/.git", "gitdir: /olmayan/yer\n")],
    'HEAD boş' => [fn ($d) => vitalwatchWrite("{$d}/.git/HEAD", '')],
]);

test('ortam değişkeni .git\'e üstün gelir, sha kısaltılır', function () {
    vitalwatchWrite("{$this->dir}/.git/HEAD", str_repeat('9', 40));
    putenv('SOURCE_VERSION= '.VITALWATCH_SHA.' ');

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => 'SOURCE_VERSION']);
});

test('ortam değişkenlerinde sıra korunur, boş olan atlanır', function () {
    putenv('GIT_COMMIT=  ');
    $_SERVER['COMMIT_SHA'] = 'second';
    $_ENV['CI_COMMIT_SHA'] = 'sonuncu';

    expect(Release::detect($this->dir))->toBe(['value' => 'second', 'source' => 'COMMIT_SHA']);
});

test('sha olmayan değer olduğu gibi, 64 karaktere kadar', function () {
    $_ENV['HEROKU_SLUG_COMMIT'] = ' v2.4.1-'.str_repeat('x', 100);

    $result = Release::detect($this->dir);

    expect($result['source'])->toBe('HEROKU_SLUG_COMMIT')
        ->and($result['value'])->toBe('v2.4.1-'.str_repeat('x', 57));
});

test('7 haneli sha küçük harfe çevrilir', function () {
    putenv('GIT_SHA=ABCDEF1');

    expect(Release::detect($this->dir)['value'])->toBe('abcdef1');
});

test('VITALWATCH_RELEASE her şeye üstün gelir, olduğu gibi', function () {
    vitalwatchWrite("{$this->dir}/.git/HEAD", VITALWATCH_SHA);
    putenv('GIT_COMMIT='.VITALWATCH_SHA);

    expect(Release::detect($this->dir, '  ABCDEF0123456789ABCDEF  '))
        ->toBe(['value' => 'ABCDEF0123456789ABCDEF', 'source' => 'VITALWATCH_RELEASE'])
        ->and(Release::detect($this->dir, str_repeat('r', 80))['value'])->toBe(str_repeat('r', 64))
        ->and(Release::detect($this->dir, '   ')['source'])->toBe('GIT_COMMIT');
});
