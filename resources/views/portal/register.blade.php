@extends('layouts.app')
@section('title', 'Öğrenci kaydı')
@section('content')
<div class="card" style="max-width:760px;margin:24px auto">
    <h1>Muafiyet başvurusu — öğrenci hesabı</h1>
    <p class="sub">Atatürk Üniversitesi Mühendislik Fakültesi. Hesabınızla daha önce aldığınız dersleri girip belgelerinizi yükleyerek başvurunuzu komisyona gönderebilirsiniz.</p>
    <form method="POST" action="{{ route('register.store') }}" class="form-grid">
        @csrf
        <div><label for="first_name">Ad *</label><input id="first_name" name="first_name" value="{{ old('first_name') }}" required></div>
        <div><label for="last_name">Soyad *</label><input id="last_name" name="last_name" value="{{ old('last_name') }}" required></div>
        <div><label for="student_no">AÜ öğrenci no (varsa)</label><input id="student_no" name="student_no" value="{{ old('student_no') }}"></div>
        <div><label for="email">E-posta *</label><input id="email" type="email" name="email" value="{{ old('email') }}" required></div>
        <div><label for="phone">Telefon</label><input id="phone" name="phone" value="{{ old('phone') }}"></div>
        <div><label for="admission_type">Kayıt türü *</label>
            <select id="admission_type" name="admission_type">@foreach (\App\Models\Student::ADMISSION_TYPES as $k => $v)<option value="{{ $k }}" @selected(old('admission_type', 'yatay') === $k)>{{ $v }}</option>@endforeach</select>
        </div>
        <div class="full"><label for="program_id">Kayıt olduğunuz AÜ programı *</label>
            <select id="program_id" name="program_id" required><option value="">Seçin</option>@foreach ($programs as $p)<option value="{{ $p->id }}" @selected((string) old('program_id') === (string) $p->id)>{{ $p->full_name }}</option>@endforeach</select>
        </div>
        <div><label for="previous_institution">Önceki üniversite *</label><input id="previous_institution" name="previous_institution" value="{{ old('previous_institution') }}" required placeholder="ör. Karadeniz Teknik Üniversitesi"></div>
        <div><label for="previous_program">Önceki bölüm/program</label><input id="previous_program" name="previous_program" value="{{ old('previous_program') }}"></div>
        <div><label for="password">Parola * (en az 8 karakter)</label><input id="password" type="password" name="password" required autocomplete="new-password"></div>
        <div><label for="password_confirmation">Parola (tekrar) *</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
        <div class="full row"><button>Hesap oluştur</button> <a href="{{ route('login') }}">Hesabım var, giriş yap</a></div>
    </form>
</div>
@endsection
