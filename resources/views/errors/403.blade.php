@extends('layouts.app')

@section('title', 'ไม่มีสิทธิ์เข้าถึง')

@section('content')
<div style="min-height:100%;display:grid;place-items:center;padding:24px 16px;">
    <div style="max-width:420px;text-align:center;">
        <div class="brand" style="justify-content:center;"><span class="brand-mark">A</span> Aura</div>
        <h1 style="font-size:22px;margin:20px 0 6px;">ไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p class="muted" style="margin:0 0 22px;">
            @auth
                บัญชี {{ auth()->user()->email ?? auth()->user()->name }} ยังไม่ได้รับสิทธิ์ผู้ดูแลระบบ
            @else
                {{ $exception->getMessage() ?: 'กรุณาเข้าสู่ระบบด้วยบัญชีที่มีสิทธิ์' }}
            @endauth
        </p>
        @auth
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn" type="submit">ออกจากระบบและใช้บัญชีอื่น</button>
            </form>
        @else
            <a class="btn" href="{{ route('login') }}">กลับไปหน้าเข้าสู่ระบบ</a>
        @endauth
    </div>
</div>
@endsection
