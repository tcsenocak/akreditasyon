@extends('layouts.app')
@section('title', $student->exists ? 'Öğrenciyi düzenle' : 'Öğrenci hesabı aç')
@section('content')
<div class="page-head">
    <div>
        <h1>{{ $student->exists ? $student->full_name : 'Öğrenci hesabı aç' }}</h1>
        <div class="sub">Komisyon yalnız kimlik ve program bilgisini girer. Önceki öğrenim, dersler, eşleştirmeler ve belgeler öğrenci tarafından tamamlanır.</div>
    </div>
</div>
<form method="POST" action="{{ $student->exists ? route('students.update', $student) : route('students.store') }}" class="card" style="max-width:760px">
    @csrf
    @if ($student->exists) @method('PUT') @endif
    <div class="form-grid">
        <div><label for="first_name">Ad *</label><input id="first_name" name="first_name" value="{{ old('first_name', $student->first_name) }}" required></div>
        <div><label for="last_name">Soyad *</label><input id="last_name" name="last_name" value="{{ old('last_name', $student->last_name) }}" required></div>
        <div><label for="student_no">AÜ öğrenci no</label><input id="student_no" name="student_no" value="{{ old('student_no', $student->student_no) }}"></div>
        <div><label for="admission_type">Kayıt türü</label>
            <select id="admission_type" name="admission_type">
                @foreach (\App\Models\Student::ADMISSION_TYPES as $key => $label)
                    <option value="{{ $key }}" @selected(old('admission_type', $student->admission_type ?: 'yatay') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="full"><label for="program_id">Kayıt olduğu AÜ programı *</label>
            <select id="program_id" name="program_id" required>
                <option value="">Seçin</option>
                @foreach ($programs as $program)
                    <option value="{{ $program->id }}" @selected((string) old('program_id', $student->program_id) === (string) $program->id)>{{ $program->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="full"><label for="curriculum_id">Tabi olacağı öğretim planı <span class="muted">(boşsa aktif plan)</span></label>
            <select id="curriculum_id" name="curriculum_id">
                <option value="">— aktif plan —</option>
                @foreach ($programs as $program)
                    @foreach ($program->curricula->sortByDesc('start_year') as $curriculum)
                        <option value="{{ $curriculum->id }}" @selected((string) old('curriculum_id', $student->curriculum_id) === (string) $curriculum->id)>{{ $program->full_name }} — {{ $curriculum->title }}</option>
                    @endforeach
                @endforeach
            </select>
        </div>
        @unless ($student->exists)
            <div class="full"><label for="account_email">Öğrencinin e-postası * <span class="muted">(panel hesabı açılır; öğrenci bu adrese gelen bağlantıyla parolasını kendisi belirler)</span></label><input id="account_email" type="email" name="account_email" value="{{ old('account_email') }}" required></div>
        @endunless
        <div class="full"><label for="notes">Komisyon notu</label><textarea id="notes" name="notes" style="min-height:60px;font-family:inherit">{{ old('notes', $student->notes) }}</textarea></div>
    </div>
    <p class="row" style="margin:14px 0 0"><button>{{ $student->exists ? 'Kaydet' : 'Hesabı aç' }}</button> <a href="{{ $student->exists ? route('students.show', $student) : route('students.index') }}">Vazgeç</a></p>
</form>
@endsection
