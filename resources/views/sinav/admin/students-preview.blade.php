@extends('sinav.admin.layout')
@section('title', 'Önizleme')
@section('sinav-content')
<h1>Önizleme</h1>
<div class="card">
  <ul class="small">
    <li><strong>{{ count($plan['new']) }}</strong> yeni öğrenci eklenecek</li>
    <li><strong>{{ count($plan['enrollments']) }}</strong> ders üyeliği oluşturulacak</li>
    <li><strong>{{ count($plan['names']) }}</strong> satırda ad soyad bulundu</li>
    @if (count($plan['name_updates']))<li><strong>{{ count($plan['name_updates']) }}</strong> kayıtlı öğrencinin adı güncellenecek</li>@endif
    <li><strong>{{ $plan['duplicates'] }}</strong> satır zaten kayıtlı veya listede tekrar ettiği için atlanacak</li>
    <li><strong>{{ count($plan['problems']) }}</strong> satır sorunlu olduğu için eklenmeyecek</li>
  </ul>
  @if (count($plan['problems']))
    <div class="alert warn" style="max-height:220px;overflow:auto">
      @foreach ($plan['problems'] as $p)
        <div>Satır {{ $p['line'] }}@if (!empty($p['student_no'])) ({{ $p['student_no'] }})@endif: {{ $p['problem'] }}</div>
      @endforeach
    </div>
  @endif
  <div class="row">
    <form method="get" action="{{ route('sinav.admin.students.add') }}" class="inline">
      <input type="hidden" name="ders" value="{{ $courseId }}"><input type="hidden" name="grup" value="{{ $groupId }}">
      <button class="btn secondary">Geri</button>
    </form>
    <form method="post" action="{{ route('sinav.admin.students.apply') }}" class="inline">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}">
      <button class="btn" @if (count($plan['enrollments']) === 0 && count($plan['name_updates']) === 0) disabled @endif>Aktar</button>
    </form>
  </div>
</div>
@endsection
