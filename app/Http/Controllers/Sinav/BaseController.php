<?php

namespace App\Http\Controllers\Sinav;

use App\Http\Controllers\Controller;
use App\Models\Sinav\Term;
use App\Services\Sinav\Access;
use App\Services\Sinav\Terms;
use App\Services\Sinav\UserError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class BaseController extends Controller
{
    /**
     * Sorguyu kullanıcının yönetebildiği derslerle sınırlar (komisyon: sınırsız; hoca: kendi dersleri).
     */
    protected function ownCourses(Builder $query, string $column = 'course_id'): Builder
    {
        $ids = Access::courseIds();

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /** Modül dönemsiz çalışır: her sayfa arka plandaki tek kaydı kullanır. */
    protected function selectedTerm(?Request $request = null): Term
    {
        return Terms::current();
    }

    /** İşlemi çalıştırır; beklenen hatalar kullanıcıya mesaj olarak döner. */
    protected function attempt(callable $fn, $successMessage = null, $redirect = null)
    {
        try {
            $result = $fn();
            $msg = is_string($result) ? $result : $successMessage;
            $to = $redirect ? redirect($redirect) : back();

            return $msg ? $to->with('success', $msg) : $to;
        } catch (UserError $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /** JSON yanıtlı işlemler (tarayıcıdan dosya yüklemeleri) için. */
    protected function attemptJson(callable $fn)
    {
        try {
            $result = $fn();

            return response()->json(['ok' => true, 'message' => is_string($result) ? $result : '', 'data' => is_array($result) ? $result : null]);
        } catch (UserError $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
