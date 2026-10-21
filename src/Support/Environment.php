<?php

namespace CestnyTR\VitalWatch\Support;

/**
 * Ortam adı normalleştirme — Node ve Python paketleriyle aynı tablo.
 *
 * Hub yalnızca production, staging ve local kabul ediyor; küme dışındaki
 * olayı — canlılık dahil — 204 dönüp atıyor. VITALWATCH_ENV boşken
 * `APP_ENV` gönderiliyordu: `prod`, `development`, `testing` ile kurulan
 * proje hiçbir şey göndermiyormuş gibi görünüyordu. Yaygın takma adlar
 * karşılığına çevrilir; tanınmayan değer olduğu gibi gider (hub onu panelde
 * "ortam" reddi olarak sayar) ve `vitalwatch:status` hata verir.
 */
class Environment
{
    public const ACCEPTED = ['production', 'staging', 'local'];

    private const ALIASES = [
        'production' => 'production', 'prod' => 'production', 'live' => 'production',
        'staging' => 'staging', 'stage' => 'staging', 'stg' => 'staging', 'preprod' => 'staging', 'uat' => 'staging',
        'local' => 'local', 'dev' => 'local', 'development' => 'local', 'test' => 'local', 'testing' => 'local',
    ];

    public static function normalize(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return 'production';
        }

        return self::ALIASES[strtolower($value)] ?? $value;
    }

    public static function accepted(string $env): bool
    {
        return in_array($env, self::ACCEPTED, true);
    }
}
