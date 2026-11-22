<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

/**
 * Basit anahtar-değer ayarları: institution_name, site_title, qr_code.
 * Etkin dönem ayrıca saklanmaz: "aktif" durumdaki dönem odur.
 */
class Setting extends Model
{
    protected $table = 'sinav_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    const DEFAULTS = [
        'institution_name' => '',
        'site_title' => 'Sınav Cevap Anahtarı Sistemi',
        'qr_code' => '',
    ];

    public static function get($key)
    {
        $row = static::query()->find($key);

        return $row ? (string) $row->value : (self::DEFAULTS[$key] ?? '');
    }

    public static function put($key, $value)
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function all_()
    {
        $out = self::DEFAULTS;
        foreach (static::query()->get() as $row) {
            $out[$row->key] = (string) $row->value;
        }

        return $out;
    }
}
