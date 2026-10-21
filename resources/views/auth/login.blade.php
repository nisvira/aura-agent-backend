@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ')

@push('styles')
<style>
    .login-wrap { min-height: 100%; display: grid; place-items: center; padding: 24px 16px; }
    .login-card {
        width: 100%; max-width: 400px; background: var(--surface); border: 1px solid var(--border);
        border-radius: 16px; box-shadow: var(--shadow); padding: 32px 28px;
    }
    .login-card h1 { font-size: 22px; margin: 20px 0 4px; }
    .login-card .sub { margin: 0 0 24px; font-size: 14px; }
    .divider { display: flex; align-items: center; gap: 12px; margin: 22px 0; font-size: 13px; color: var(--muted); }
    .divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: var(--border); }
    .stack { display: grid; gap: 14px; }
    .w-full { width: 100%; }
    .error {
        font-size: 13px; color: var(--bad); background: color-mix(in srgb, var(--bad) 10%, transparent);
        border-radius: 8px; padding: 8px 12px; margin: 0 0 14px;
    }
    .note { margin-top: 20px; font-size: 12.5px; text-align: center; }
</style>
@endpush

@section('content')
<div class="login-wrap">
    <div class="login-card">
        <div class="brand"><span class="brand-mark">A</span> Aura · Test Console</div>
        <h1>เข้าสู่ระบบ</h1>
        <p class="sub muted">สำหรับผู้ดูแลระบบเพื่อทดสอบการทำงานของแชท RAG และ LLM</p>

        @if (session('status'))
            <p class="error">{{ session('status') }}</p>
        @endif

        <a class="btn btn-primary w-full" href="{{ route('yru.redirect', ['to' => 'console']) }}">
            เข้าสู่ระบบด้วย YRU Passport
        </a>

        <div class="divider">หรือใช้บัญชีผู้ดูแลระบบ</div>

        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ url('/login') }}" class="stack">
            @csrf
            <div class="field">
                <label for="email">อีเมล</label>
                <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">รหัสผ่าน</label>
                <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <button class="btn w-full" type="submit">เข้าสู่ระบบ</button>
        </form>

        <p class="note muted">เฉพาะผู้ที่ได้รับสิทธิ์ผู้ดูแลระบบเท่านั้น</p>
    </div>
</div>
@endsection
