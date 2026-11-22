<?php

namespace App\Services\Sinav;

use App\Models\Sinav\AccFile;
use App\Models\Sinav\AccOutput;
use App\Models\Sinav\AccSection;
use App\Models\Sinav\Course;
use App\Models\Sinav\ExamFile;
use Illuminate\Support\Facades\DB;

/**
 * Ders akreditasyon dosyası: dönem sonunda her ders için belgelerin toplanıp tek PDF'te
 * birleştirilmesi. YALNIZCA yönetici erişir. İcmal ve program çıktısı raporu öğrenci adı ve
 * notu içerir; bu kayıtlar akreditasyon arşivi olarak dönem kapatmada SİLİNMEZ.
 *
 * PDF birleştirme ve Excel→tablo çevirme tarayıcıda yapılır (pdf-lib); sunucu dosyaları saklar.
 */
class Accreditation
{
    /** Bölümleri getirir; ilk açılışta 7 sabit bölümü oluşturur. */
    public static function sections(Course $course)
    {
        $list = AccSection::query()->where('course_id', $course->id)->orderBy('sort')->get();
        if ($list->isEmpty()) {
            foreach (AccSection::DEFAULTS as $i => $d) {
                AccSection::query()->create(['course_id' => $course->id, 'kind' => $d['kind'], 'title' => $d['title'], 'sort' => $i + 1]);
            }
            $list = AccSection::query()->where('course_id', $course->id)->orderBy('sort')->get();
        }

        return $list;
    }

    public static function files(Course $course)
    {
        return AccFile::query()->where('course_id', $course->id)->orderBy('sort')->orderBy('name')->get();
    }

    public static function addSection(Course $course, $title)
    {
        $t = trim((string) $title);
        if ($t === '') {
            throw new UserError('Bölüm adı boş olamaz.');
        }
        if (mb_strlen($t) > 120) {
            throw new UserError('Bölüm adı en fazla 120 karakter olabilir.');
        }
        $sort = (int) AccSection::query()->where('course_id', $course->id)->max('sort') + 1;
        AccSection::query()->create(['course_id' => $course->id, 'kind' => 'custom', 'title' => $t, 'sort' => $sort]);
        Util::log('acc.addSection', 'course', $course->id);
    }

    public static function renameSection(AccSection $s, $title)
    {
        $t = trim((string) $title);
        if ($t === '') {
            throw new UserError('Bölüm adı boş olamaz.');
        }
        $s->update(['title' => mb_substr($t, 0, 120)]);
    }

    /** Bölümü bir yukarı/aşağı taşır (komşusuyla sırasını değiştirir). */
    public static function moveSection(AccSection $s, $dir)
    {
        $list = AccSection::query()->where('course_id', $s->course_id)->orderBy('sort')->get()->values();
        self::swap($list, $s->id, $dir);
    }

    public static function moveFile(AccFile $f, $dir)
    {
        $list = AccFile::query()->where('section_id', $f->section_id)->orderBy('sort')->orderBy('name')->get()->values();
        self::swap($list, $f->id, $dir);
    }

    private static function swap($list, $id, $dir)
    {
        // Sıra numaraları önce 1..N yapılır (eşit sıralar olsa bile doğru taşınsın).
        foreach ($list as $i => $x) {
            if ((int) $x->sort !== $i + 1) {
                $x->update(['sort' => $i + 1]);
            }
        }
        $idx = $list->search(function ($x) use ($id) {
            return (int) $x->id === (int) $id;
        });
        $other = $list->get($idx + ($dir < 0 ? -1 : 1));
        $cur = $list->get($idx);
        if ($idx === false || ! $other || ! $cur) {
            return;
        }
        DB::transaction(function () use ($cur, $other) {
            $a = $cur->sort;
            $cur->update(['sort' => $other->sort]);
            $other->update(['sort' => $a]);
        });
    }

    /** Yalnızca sonradan eklenen bölümler silinebilir; dosyalarıyla birlikte silinir. */
    public static function deleteSection(AccSection $s)
    {
        if ($s->kind !== 'custom') {
            throw new UserError('Sabit bölümler silinemez.');
        }
        foreach (AccFile::query()->where('section_id', $s->id)->get() as $f) {
            Files::delete($f->path);
        }
        $cid = $s->course_id;
        $s->delete();
        Util::log('acc.deleteSection', 'course', $cid);
    }

    private static function nextSort($sectionId)
    {
        return (int) AccFile::query()->where('section_id', $sectionId)->max('sort') + 1;
    }

    /**
     * Dosya ekler. PDF/PNG/JPG olduğu gibi saklanır (tür içeriğe bakılarak belirlenir).
     * Excel dosyası tarayıcıda tabloya çevrilip PDF olarak gelir ($source = 'xlsx').
     */
    public static function upload(AccSection $s, $uploadId, $name, $source)
    {
        $f = Files::take($uploadId, config('sinav.max_acc_file'));
        $mime = Util::sniffMime($f['head']);
        if (! $mime) {
            @unlink($f['tmp']);
            throw new UserError($name.': yalnızca PDF, PNG, JPG veya Excel (.xlsx) yüklenebilir.');
        }
        $path = Files::keep($f['tmp'], 'acc/'.$s->course_id, Files::extFor($mime));
        AccFile::query()->create([
            'course_id' => $s->course_id, 'section_id' => $s->id, 'name' => mb_substr((string) $name, 0, 200), 'mime' => $mime,
            'size' => $f['size'], 'path' => $path, 'sort' => self::nextSort($s->id), 'source' => $source === 'xlsx' ? 'xlsx' : 'upload',
        ]);
        Util::log('acc.upload', 'course', $s->course_id, 'ok', ($source === 'xlsx' ? 'xlsx' : 'upload').', '.round($f['size'] / 1024).' KB');
    }

    /** Sınav bölümüne yüklenmiş dosyayı akreditasyon dosyasına kopyalar. */
    public static function copyFromExam(AccSection $s, ExamFile $ef)
    {
        AccFile::query()->create([
            'course_id' => $s->course_id, 'section_id' => $s->id, 'name' => $ef->name, 'mime' => $ef->mime, 'size' => $ef->size,
            'path' => Files::copy($ef->path, 'acc/'.$s->course_id, Files::extFor($ef->mime)), 'sort' => self::nextSort($s->id), 'source' => 'exam',
        ]);
        Util::log('acc.copyFromExam', 'course', $s->course_id);
    }

    public static function deleteFile(AccFile $f)
    {
        Files::delete($f->path);
        $cid = $f->course_id;
        $f->delete();
        Util::log('acc.deleteFile', 'course', $cid);
    }

    /** Oluşturulan birleşik PDF'i sistemde saklar (indirmeye ek olarak). */
    public static function saveOutput(Course $course, $uploadId, $name, $pageCount)
    {
        $max = config('sinav.max_acc_output');
        try {
            $f = Files::take($uploadId, $max);
        } catch (UserError $e) {
            throw new UserError('PDF indirildi ama sistemde saklanamadı: '.$e->getMessage().' Bilgisayarınızdaki kopyayı Drive\'a yükleyin.');
        }
        if (Util::sniffMime($f['head']) !== 'application/pdf') {
            @unlink($f['tmp']);
            throw new UserError('Saklanacak dosya PDF değil.');
        }
        $path = Files::keep($f['tmp'], 'acc-out/'.$course->id, 'pdf');
        AccOutput::query()->create(['course_id' => $course->id, 'name' => mb_substr((string) $name, 0, 200), 'size' => $f['size'], 'page_count' => (int) $pageCount, 'path' => $path]);
        Util::log('acc.saveOutput', 'course', $course->id, 'ok', (int) $pageCount.' sayfa');
    }

    public static function deleteOutput(AccOutput $o)
    {
        Files::delete($o->path);
        $cid = $o->course_id;
        $o->delete();
        Util::log('acc.deleteOutput', 'course', $cid);
    }

    /** Ders silinirken ders dosyasının belgelerini de diskten siler. */
    public static function deleteAllForCourse(Course $course)
    {
        foreach (AccFile::query()->where('course_id', $course->id)->get() as $f) {
            Files::delete($f->path);
        }
        foreach (AccOutput::query()->where('course_id', $course->id)->get() as $o) {
            Files::delete($o->path);
        }
    }
}
