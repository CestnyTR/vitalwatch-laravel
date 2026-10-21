<?php

use CestnyTR\VitalWatch\Support\Environment;

/*
| Hub yalnızca production/staging/local kabul ediyor ve küme dışını —
| canlılık dahil — sessizce atıyor. APP_ENV=prod ya da development ile
| kurulan proje hiçbir şey göndermiyormuş gibi görünüyordu. Tablo Node ve
| Python paketleriyle aynı.
*/
test('yaygın ortam adları hub ın kabul ettiği karşılığa çevrilir', function ($input, string $expected) {
    expect(Environment::normalize($input))->toBe($expected);
})->with([
    [null, 'production'], ['', 'production'], ['prod', 'production'], ['Live', 'production'],
    ['stage', 'staging'], ['UAT', 'staging'], ['preprod', 'staging'],
    ['development', 'local'], ['dev', 'local'], ['testing', 'local'], ['test', 'local'],
    ['production', 'production'], ['staging', 'staging'], ['local', 'local'],
]);

// Bilinmeyeni production saymak test verisini canlıya karıştırırdı.
test('tanınmayan ortam olduğu gibi kalır ve kabul edilmez', function () {
    expect(Environment::normalize('qa-eu'))->toBe('qa-eu')
        ->and(Environment::accepted('qa-eu'))->toBeFalse();
});
