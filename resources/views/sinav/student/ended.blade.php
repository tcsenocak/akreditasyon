@extends('sinav.student.layout')
@section('content')
<div class="st-box">
  <h1>Erişim sona erdi</h1>
  <p>Kaydınız kaldırılmış ya da oturum süreniz dolmuş olabilir.</p>
  <a class="st-btn" href="{{ route('sinav.home') }}">Giriş ekranına dön</a>
</div>
@endsection
