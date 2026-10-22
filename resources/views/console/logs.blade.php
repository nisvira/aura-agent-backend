@extends('layouts.app')

@section('title', 'Logs')

@php
    $ms = fn ($v) => $v === null ? '–' : number_format($v / 1000, 1).' วิ';
    $when = fn ($dt) => $dt?->timezone($tz)->format('d/m/Y H:i:s');
    $eventLabels = [
        'auth.login.success' => 'เข้าสู่ระบบสำเร็จ',
        'auth.login.failed' => 'เข้าสู่ระบบไม่สำเร็จ',
        'auth.logout' => 'ออกจากระบบ',
        'auth.token.issued' => 'ออก token',
        'auth.state_mismatch' => 'OAuth state ไม่ตรง',
        'auth.invalid_api_key' => 'API key ไม่ถูกต้อง',
        'admin.granted' => 'ให้สิทธิ์แอดมิน',
        'access.denied' => 'ถูกปฏิเสธสิทธิ์ (403)',
        'ratelimit.exceeded' => 'เกินโควตา (429)',
        'user.created' => 'สร้างผู้ใช้ใหม่',
        'chat.session.deleted' => 'ลบห้องแชท',
        'support.act_as' => 'ถามแทนนักศึกษา',
        'support.act_as.denied' => 'ถามแทนนักศึกษาไม่ได้ (ไม่มีสิทธิ์)',
    ];
    $codeLabels = [
        'PASSPORT_REQUIRED' => 'ยังไม่ได้ login ด้วย YRU Passport',
        'NOT_STUDENT' => 'ไม่ใช่บัญชีนักศึกษา',
        'TOKEN_EXPIRED' => 'Passport session หมดอายุ',
        'UPSTREAM_UNAVAILABLE' => 'ระบบกิจกรรม / Passport ไม่ตอบ',
        'LLM_UNAVAILABLE' => 'LLM ของ agent ไม่พร้อม',
        'AGENT_UNAVAILABLE' => 'ติดต่อ AURA-AGENT ไม่ได้',
        'AGENT_REJECTED' => 'AURA-AGENT ปฏิเสธ (service key)',
        'INCOMPLETE' => 'stream จบก่อนตอบเสร็จ',
        'INTERNAL' => 'error ภายใน agent',
    ];
    $warnEvents = ['support.act_as.denied', 'auth.login.failed', 'auth.state_mismatch', 'auth.invalid_api_key', 'access.denied', 'ratelimit.exceeded'];
@endphp

@push('styles')
<style>
    .page { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; display: grid; gap: 28px; }
    .page h2 { font-size: 17px; margin: 0 0 4px; }
    .page .lead { margin: 0 0 12px; font-size: 13.5px; }
    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .tile { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
    .tile-label { font-size: 12.5px; color: var(--muted); }
    .tile-value { font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; margin-top: 2px; }
    .tile-value.warn { color: var(--warn); }
    .table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow-x: auto; }
    table.data { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    table.data th {
        text-align: left; font-weight: 600; font-size: 12.5px; color: var(--muted);
        padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; background: var(--surface-2);
    }
    table.data td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
    table.data tr:last-child td { border-bottom: 0; }
    table.data td.num, table.data th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.data td.nowrap { white-space: nowrap; }
    .question { max-width: 560px; }
    .pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }
    .pill.ok { background: color-mix(in srgb, var(--ok) 14%, transparent); color: var(--ok); }
    .pill.bad { background: color-mix(in srgb, var(--bad) 14%, transparent); color: var(--bad); }
    .pill.warn { background: color-mix(in srgb, var(--warn) 16%, transparent); color: var(--warn); }
    .pill.neutral { background: var(--surface-2); color: var(--muted); }
    .empty-row { text-align: center; color: var(--muted); padding: 22px !important; }
    .filter { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
    .filter select { padding: 7px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font: inherit; font-size: 13.5px; }
    .meta { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: var(--muted); word-break: break-all; }
    .pager { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 13px; }
</style>
@endpush

@section('content')
@include('console._topbar', ['active' => 'logs'])

<div class="page">
    <section>
        <h2>ภาพรวม 7 วันล่าสุด</h2>
        <p class="lead muted">นับเฉพาะคำถามจริง ไม่รวมงานเบื้องหลังของ Open WebUI (ตั้งชื่อห้อง, tag)</p>
        <div class="tiles">
            <div class="tile"><div class="tile-label">คำถาม</div><div class="tile-value">{{ number_format($summary['questions']) }}</div></div>
            <div class="tile"><div class="tile-label">ผู้ใช้</div><div class="tile-value">{{ number_format($summary['users']) }}</div></div>
            <div class="tile">
                <div class="tile-label">อัตรา error</div>
                <div @class(['tile-value', 'warn' => ($summary['error_rate'] ?? 0) > 0.05])>{{ $summary['error_rate'] === null ? '–' : number_format($summary['error_rate'] * 100, 1).'%' }}</div>
            </div>
            <div class="tile"><div class="tile-label">เวลาตอบ p95</div><div class="tile-value">{{ $ms($summary['p95_ms']) }}</div></div>
            <div class="tile">
                <div class="tile-label">ถูกปฏิเสธ (ไม่ใช่นักศึกษา / ไม่ได้ login)</div>
                <div class="tile-value">{{ number_format($summary['turned_away']) }}</div>
            </div>
            <div class="tile"><div class="tile-label">งานเบื้องหลัง Open WebUI</div><div class="tile-value">{{ number_format($summary['tasks']) }}</div></div>
        </div>
    </section>

    <section>
        <h2>รายวัน (14 วัน)</h2>
        <p class="lead muted">เวลาตามประเทศไทย · Error ไม่รวมคำถามที่ถูกปฏิเสธ · เวลาเฉลี่ยนับเฉพาะคำถามที่ตอบสำเร็จ</p>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>วันที่</th><th class="num">คำถาม</th><th class="num">ตอบแล้ว</th><th class="num">Error</th>
                        <th class="num">ถูกปฏิเสธ</th><th class="num">ยกเลิกกลางคัน</th><th class="num">ถึงคำแรก</th>
                        <th class="num">เวลาตอบเฉลี่ย</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daily as $d)
                        <tr>
                            <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($d->day)->format('d/m/Y') }}</td>
                            <td class="num">{{ number_format($d->total) }}</td>
                            <td class="num">{{ number_format($d->answered) }}</td>
                            <td class="num">{{ $d->errors - $d->turned_away ?: '–' }}</td>
                            <td class="num">{{ $d->turned_away ?: '–' }}</td>
                            <td class="num">{{ $d->aborted ?: '–' }}</td>
                            <td class="num">{{ $ms($d->avg_ttft_ms) }}</td>
                            <td class="num">{{ $ms($d->avg_total_ms) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-row">ยังไม่มีข้อมูล</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
        <div>
            <h2>Tools ที่ AURA-AGENT ใช้ (7 วัน)</h2>
            <p class="lead muted">ข้อมูลที่ agent ไปดึงมาตอบ นับต่อคำถามที่ตอบสำเร็จ</p>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Tool</th><th class="num">ครั้ง</th></tr></thead>
                    <tbody>
                        @forelse ($tools as $tool => $count)
                            <tr><td class="meta">{{ $tool }}</td><td class="num">{{ number_format($count) }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="empty-row">ยังไม่มีคำตอบที่ใช้ tool</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div>
            <h2>สาเหตุของ error (7 วัน)</h2>
            <p class="lead muted">รหัสจาก AURA-AGENT หรือจาก backend ก่อนส่งให้ agent</p>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>รหัส</th><th>ความหมาย</th><th class="num">ครั้ง</th></tr></thead>
                    <tbody>
                        @forelse ($errorCodes as $row)
                            <tr>
                                <td class="meta">{{ $row->code }}</td>
                                <td>{{ $codeLabels[$row->code] ?? '–' }}</td>
                                <td class="num">{{ number_format($row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="empty-row">ไม่มี error</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section>
        <h2>คำขอที่มีปัญหา</h2>
        <p class="lead muted">Error, ถูกปฏิเสธ หรือผู้ใช้ปิดหน้าก่อนตอบเสร็จ (20 รายการล่าสุด)</p>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>สถานะ</th><th>ระบบ</th><th class="num">เวลา</th><th>รายละเอียด</th><th>Request ID</th></tr></thead>
                <tbody>
                    @forelse ($failures as $row)
                        <tr>
                            <td class="nowrap">{{ $when($row->created_at) }}</td>
                            <td class="nowrap">{{ $row->user?->name ?? '–' }}{{ $row->is_task ? ' · task' : '' }}</td>
                            <td><span @class(['pill', 'ok' => $row->status === 'success', 'bad' => $row->status === 'error', 'warn' => $row->status === 'aborted'])>{{ $row->status }}</span></td>
                            <td><span class="pill neutral">{{ $row->is_task ? 'Ollama (task)' : 'AURA-AGENT' }}</span></td>
                            <td class="num">{{ $ms($row->total_ms) }}</td>
                            <td class="meta">{{ $row->error ?? '' }}</td>
                            <td class="meta">{{ $row->request_id }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-row">ไม่มีปัญหา</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2>Audit log</h2>
        <p class="lead muted">เหตุการณ์ด้านความปลอดภัยและการเปลี่ยนแปลงข้อมูล เก็บ {{ config('logging.retention.audit_days') }} วัน</p>
        <form class="filter" method="GET" action="{{ route('console.logs') }}">
            <select name="event" onchange="this.form.submit()">
                <option value="">ทุกเหตุการณ์</option>
                @foreach ($events as $e)
                    <option value="{{ $e }}" @selected($e === $event)>{{ $eventLabels[$e] ?? $e }}</option>
                @endforeach
            </select>
            @if ($event !== '')
                <a class="btn btn-sm" href="{{ route('console.logs') }}">ล้างตัวกรอง</a>
            @endif
        </form>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>เวลา</th><th>เหตุการณ์</th><th>ผู้ใช้</th><th>เป้าหมาย</th><th>IP</th><th>รายละเอียด</th></tr></thead>
                <tbody>
                    @forelse ($audit as $row)
                        <tr>
                            <td class="nowrap">{{ $when($row->created_at) }}</td>
                            <td class="nowrap"><span @class(['pill', 'warn' => in_array($row->event, $warnEvents, true), 'neutral' => ! in_array($row->event, $warnEvents, true)])>{{ $eventLabels[$row->event] ?? $row->event }}</span></td>
                            <td class="nowrap">{{ $row->user?->email ?? $row->user?->name ?? '–' }}</td>
                            <td class="nowrap">{{ $row->target_type ? $row->target_type.' #'.$row->target_id : '–' }}</td>
                            <td class="nowrap meta">{{ $row->ip ?? '–' }}</td>
                            <td class="meta">{{ $row->metadata ? json_encode($row->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-row">ยังไม่มีเหตุการณ์</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($audit->hasPages())
            <div class="pager">
                <span class="muted">หน้า {{ $audit->currentPage() }} / {{ $audit->lastPage() }} · ทั้งหมด {{ number_format($audit->total()) }} รายการ</span>
                <span style="display:flex;gap:8px;">
                    @if ($audit->previousPageUrl()) <a class="btn btn-sm" href="{{ $audit->previousPageUrl() }}">ก่อนหน้า</a> @endif
                    @if ($audit->nextPageUrl()) <a class="btn btn-sm" href="{{ $audit->nextPageUrl() }}">ถัดไป</a> @endif
                </span>
            </div>
        @endif
    </section>
</div>
@endsection
