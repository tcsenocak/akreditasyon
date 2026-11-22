<?php

namespace App\Models\Sinav;

use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    protected $table = 'sinav_terms';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'access_ends_at' => 'datetime',
        'closure_started_at' => 'datetime',
        'closure_closed_at' => 'datetime',
    ];

    /** Modül dönemsiz çalışır; arka plandaki tek kaydın süresi pratikte sınırsızdır. */
    const OPEN_UNTIL = '2099-12-31 23:59:59';

    public function courses()
    {
        return $this->hasMany(Course::class, 'term_id')->orderBy('code');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'term_id');
    }

    /** Kayıt değiştirilebilir mi (eski sürümden kalan kapanmış dönemler değiştirilemez). */
    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'active'], true);
    }

    /** Öğrenciler şu an girebilir mi. */
    public function openForStudents(): bool
    {
        return $this->status === 'active' && now()->lt($this->access_ends_at);
    }
}
