@once
@push('styles')
<style>
    .topbar {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 10px 16px; background: var(--surface); border-bottom: 1px solid var(--border);
    }
    .topbar-left { display: flex; align-items: center; gap: 20px; min-width: 0; }
    .nav { display: flex; gap: 4px; }
    .nav a {
        padding: 6px 12px; border-radius: 8px; font-size: 14px; font-weight: 500;
        color: var(--muted); text-decoration: none;
    }
    .nav a:hover { background: var(--surface-2); color: var(--text); }
    .nav a.active { background: var(--accent-soft); color: var(--accent); }
    .who { display: flex; align-items: center; gap: 12px; font-size: 14px; min-width: 0; }
    .who-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .badge {
        font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px;
        background: var(--accent-soft); color: var(--accent);
    }
    @media (max-width: 640px) { .who-name, .badge { display: none; } }
</style>
@endpush
@endonce

<header class="topbar">
    <div class="topbar-left">
        <div class="brand"><span class="brand-mark">A</span> Aura · Test Console</div>
        <nav class="nav">
            <a href="{{ route('console') }}" @class(['active' => $active === 'chat'])>แชท</a>
            @can('admin')
                <a href="{{ route('console.logs') }}" @class(['active' => $active === 'logs'])>Logs</a>
            @endcan
        </nav>
    </div>
    <div class="who">
        <span class="who-name muted">{{ auth()->user()->name }}</span>
        <span class="badge">{{ auth()->user()->effectiveRole()?->label() ?? 'ไม่มีบทบาท' }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-sm" type="submit">ออกจากระบบ</button>
        </form>
        @if (auth()->user()->passport_profile)
            <form method="POST" action="{{ route('logout.passport') }}"
                  onsubmit="return confirm('ออกจากระบบนี้และออกจาก YRU Passport ด้วย?\nครั้งต่อไปต้องกรอกรหัสผ่าน YRU Passport ใหม่')">
                @csrf
                <button class="btn btn-sm" type="submit" title="ใช้เมื่อเป็นเครื่องที่ใช้ร่วมกับผู้อื่น">ออกจาก YRU Passport</button>
            </form>
        @endif
    </div>
</header>
