<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Group extends Model
{
    protected $table = 'sinav_groups';

    protected $guarded = ['id'];

    protected $hidden = ['pin_encrypted'];

    protected $casts = ['pin_updated_at' => 'datetime'];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'group_id');
    }

    public function hasPin(): bool
    {
        return $this->pin_encrypted !== null && $this->pin_encrypted !== '';
    }

    /** Hocanın görebilmesi için çözülmüş PIN (yoksa null). */
    public function pin(): ?string
    {
        return $this->hasPin() ? Crypt::decryptString($this->pin_encrypted) : null;
    }

    /** Girilen PIN bu grubunkiyle aynı mı (zamanlamaya dayanıklı karşılaştırma). */
    public function pinMatches(string $pin): bool
    {
        $own = $this->pin();

        return $own !== null && hash_equals($own, $pin);
    }
}
