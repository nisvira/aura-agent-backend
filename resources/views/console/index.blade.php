@extends('layouts.app')

@section('title', 'Test Console')

@push('styles')
<style>
    body { overflow: hidden; }
    .app { height: 100%; display: grid; grid-template-rows: auto 1fr; }
    .layout { display: grid; grid-template-columns: 260px minmax(0, 1fr) 320px; min-height: 0; }
    .panel { background: var(--surface); border-right: 1px solid var(--border); overflow-y: auto; min-height: 0; }
    .inspector { border-right: 0; border-left: 1px solid var(--border); padding: 16px; display: grid; gap: 16px; align-content: start; }

    /* Sessions */
    .sidebar { padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .session-list { display: grid; gap: 2px; }
    .session {
        display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-radius: 8px;
        cursor: pointer; font-size: 14px; border: 1px solid transparent;
    }
    .session:hover { background: var(--surface-2); }
    .session.active { background: var(--accent-soft); border-color: color-mix(in srgb, var(--accent) 30%, transparent); }
    .session-title { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .session-del { border: 0; background: none; color: var(--muted); cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1; visibility: hidden; }
    .session:hover .session-del { visibility: visible; }
    .session-del:hover { color: var(--bad); }
    .section-label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; margin: 8px 4px 2px; }

    /* Chat */
    .chat { display: grid; grid-template-rows: 1fr auto; min-height: 0; }
    .messages { overflow-y: auto; padding: 24px 16px; display: flex; flex-direction: column; gap: 14px; }
    .msg { max-width: min(760px, 92%); padding: 10px 14px; border-radius: 14px; white-space: pre-wrap; word-wrap: break-word; }
    .msg.user { align-self: flex-end; background: var(--accent); color: var(--on-accent); border-bottom-right-radius: 4px; }
    .msg.assistant { align-self: flex-start; background: var(--surface); border: 1px solid var(--border); border-bottom-left-radius: 4px; }
    .msg.error { border-color: color-mix(in srgb, var(--bad) 50%, transparent); color: var(--bad); }
    .msg-status { font-size: 13px; color: var(--muted); font-style: italic; }
    .msg-status:empty { display: none; }
    .msg-sources { margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 12.5px; color: var(--muted); white-space: normal; }
    .typing::after { content: "▍"; animation: blink 1s steps(1) infinite; color: var(--accent); }
    @keyframes blink { 50% { opacity: 0; } }
    .empty { margin: auto; text-align: center; max-width: 520px; }
    .empty h2 { font-size: 20px; margin: 0 0 6px; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 16px; }
    .chip { padding: 7px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); cursor: pointer; font-size: 13.5px; }
    .chip:hover { border-color: var(--accent); color: var(--accent); }
    .composer { display: flex; gap: 10px; padding: 12px 16px 16px; background: var(--bg); }
    .composer textarea { flex: 1; resize: none; min-height: 46px; max-height: 180px; }

    /* Inspector */
    .card { background: var(--surface-2); border-radius: var(--radius); padding: 14px; display: grid; gap: 10px; }
    .card-head { display: flex; align-items: center; justify-content: space-between; }
    .card-head h3 { margin: 0; font-size: 14px; }
    .status-row { display: grid; grid-template-columns: 10px 1fr; gap: 4px 10px; align-items: start; font-size: 13px; }
    .dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 6px; background: var(--muted); }
    .dot.ok { background: var(--ok); }
    .dot.bad { background: var(--bad); }
    .status-name { font-weight: 600; }
    .status-detail { grid-column: 2; color: var(--muted); font-size: 12.5px; word-break: break-word; }
    .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat { background: var(--surface); border-radius: 8px; padding: 8px 10px; }
    .stat-label { font-size: 11.5px; color: var(--muted); }
    .stat-value { font-size: 16px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .src-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .src-table td { padding: 5px 4px; border-top: 1px solid var(--border); vertical-align: top; }
    .src-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; color: var(--muted); }

    @media (max-width: 1100px) { .layout { grid-template-columns: 240px minmax(0, 1fr); } .inspector { display: none; } }
    @media (max-width: 720px) { .layout { grid-template-columns: minmax(0, 1fr); } .sidebar { display: none; } }
</style>
@endpush

@section('content')
@php
    $role = auth()->user()->effectiveRole();
    $isAdmin = $role === \App\Enums\UserRole::Admin;
    // Everyone asks in their own name; AURA-AGENT scopes answers to the Passport token
    // (instructors see their advisees). The act_as_student API stays for a future support mode.
    $isInstructor = $role === \App\Enums\UserRole::Instructor;
@endphp
<div class="app">
    @include('console._topbar', ['active' => 'chat'])

    <div class="layout">
        <aside class="panel sidebar">
            <button class="btn btn-primary" id="new-chat" type="button">+ แชทใหม่</button>
            <div class="section-label">ประวัติแชท</div>
            <div class="session-list" id="sessions"><p class="muted" style="font-size:13px;padding:0 6px;">กำลังโหลด…</p></div>
        </aside>

        <main class="chat">
            <div class="messages" id="messages"></div>
            <form class="composer" id="composer">
                <textarea class="input" id="input" rows="1" placeholder="พิมพ์คำถาม… (Enter ส่ง, Shift+Enter ขึ้นบรรทัดใหม่)" maxlength="{{ config('chat.max_message_length') }}"></textarea>
                <button class="btn btn-primary" id="send" type="submit">ส่ง</button>
            </form>
        </main>

        <aside class="panel inspector">
            @if ($isAdmin)
                <section class="card">
                    <div class="card-head">
                        <h3>สถานะระบบ</h3>
                        <button class="btn btn-sm" id="refresh-status" type="button">ตรวจสอบ</button>
                    </div>
                    <div id="status" class="muted" style="font-size:13px;">กำลังตรวจสอบ…</div>
                </section>
            @endif

            <section class="card">
                <div class="card-head"><h3>คำตอบล่าสุด</h3></div>
                <div class="stats">
                    <div class="stat"><div class="stat-label">เวลาถึงคำแรก</div><div class="stat-value" id="s-ttft">–</div></div>
                    <div class="stat"><div class="stat-label">เวลาทั้งหมด</div><div class="stat-value" id="s-total">–</div></div>
                    <div class="stat"><div class="stat-label">เวลาฝั่ง agent</div><div class="stat-value" id="s-agent">–</div></div>
                    <div class="stat"><div class="stat-label">จำนวน tool</div><div class="stat-value" id="s-tool-count">–</div></div>
                </div>
                <div>
                    <div class="stat-label" style="margin-bottom:4px;">Tools ที่ AURA-AGENT ใช้</div>
                    <div id="s-tools" class="muted" style="font-size:12.5px;">–</div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const el = (tag, cls, text) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    let currentSession = null;
    let busy = false;
    const isAdmin = @json($isAdmin);
    const isInstructor = @json($isInstructor);

    const api = (path, options = {}) => fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(options.headers || {}),
        },
    });

    async function errorMessage(res) {
        if (res.status === 401 || res.status === 419) return 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
        try { return (await res.json()).message || `HTTP ${res.status}`; } catch { return `HTTP ${res.status}`; }
    }

    // ---------- Sessions ----------
    async function loadSessions() {
        const list = $('sessions');
        const res = await api('/api/chat/sessions');
        if (!res.ok) { list.replaceChildren(el('p', 'muted', await errorMessage(res))); return; }
        const { data } = await res.json();
        list.replaceChildren();
        if (!data.length) { list.append(el('p', 'muted', 'ยังไม่มีประวัติ')); return; }
        for (const s of data) {
            const row = el('div', 'session' + (s.id === currentSession ? ' active' : ''));
            row.append(el('span', 'session-title', s.title));
            const del = el('button', 'session-del', '×');
            del.title = 'ลบแชทนี้';
            del.onclick = async (e) => {
                e.stopPropagation();
                if (!confirm(`ลบ "${s.title}"?`)) return;
                await api(`/api/chat/sessions/${s.id}`, { method: 'DELETE' });
                if (s.id === currentSession) newChat();
                loadSessions();
            };
            row.append(del);
            row.onclick = () => openSession(s.id);
            list.append(row);
        }
    }

    async function openSession(id) {
        if (busy) return;
        const res = await api(`/api/chat/sessions/${id}`);
        if (!res.ok) return;
        const { data } = await res.json();
        currentSession = data.id;
        const box = $('messages');
        box.replaceChildren();
        for (const m of data.messages) addMessage(m.role, m.content, m.tools_used);
        box.scrollTop = box.scrollHeight;
        loadSessions();
    }

    function newChat() {
        currentSession = null;
        renderEmpty();
        loadSessions();
        $('input').focus();
    }

    // ---------- Messages ----------
    function renderEmpty() {
        const wrap = el('div', 'empty');
        wrap.append(el('h2', null, 'ทดสอบการถามตอบ'));
        wrap.append(el('p', 'muted', 'คำถามส่งไปที่ AURA-AGENT จริง ต้อง login ด้วย YRU Passport: นักศึกษาดูความคืบหน้าของตัวเองได้ อาจารย์และบุคลากรค้นหารอบกิจกรรมที่เปิดรับสมัครได้'));
        const chips = el('div', 'chips');
        for (const q of (isInstructor
            ? ['นักศึกษาในที่ปรึกษามีใครบ้าง', 'ใครยังทำกิจกรรมไม่ครบ', 'มีกิจกรรมอะไรเปิดรับสมัครบ้าง']
            : ['ผมยังขาดกิจกรรมหมวดไหน', 'มีกิจกรรมอะไรกำลังจะเปิด', 'แนะนำกิจกรรมให้ครบหน่อย'])) {
            const chip = el('button', 'chip', q);
            chip.type = 'button';
            chip.onclick = () => { $('input').value = q; send(); };
            chips.append(chip);
        }
        wrap.append(chips);
        $('messages').replaceChildren(wrap);
    }

    function addMessage(role, content, tools) {
        const box = $('messages');
        box.querySelector('.empty')?.remove();
        const msg = el('div', `msg ${role}`);
        const text = el('div', null, content);
        msg.append(text);
        if (tools) renderToolsInline(msg, tools);
        box.append(msg);
        box.scrollTop = box.scrollHeight;
        return { msg, text };
    }

    function renderToolsInline(msg, tools) {
        if (!tools?.length) return;
        msg.append(el('div', 'msg-sources', 'ข้อมูลที่ใช้: ' + tools.join(' · ')));
    }

    function renderToolsPanel(tools) {
        const box = $('s-tools');
        if (!tools.length) { box.replaceChildren(el('span', 'muted', 'ไม่ได้เรียก tool (ตอบจากบทสนทนา)')); return; }
        const table = el('table', 'src-table');
        for (const name of tools) {
            const tr = el('tr');
            tr.append(el('td', 'meta', name));
            table.append(tr);
        }
        box.replaceChildren(table);
    }

    const fmtSec = (ms) => ms == null ? '–' : (ms / 1000).toFixed(1) + ' วิ';
    function setStats({ ttft, total, agent, tools }) {
        $('s-ttft').textContent = fmtSec(ttft);
        $('s-total').textContent = fmtSec(total);
        $('s-agent').textContent = fmtSec(agent);
        $('s-tool-count').textContent = tools ?? '–';
    }

    // ---------- Send (Server-Sent Events over fetch) ----------
    async function send() {
        const input = $('input');
        const question = input.value.trim();
        if (!question || busy) return;

        const payload = { message: question, session_id: currentSession };

        busy = true;
        $('send').disabled = true;
        input.value = '';
        autosize();

        addMessage('user', question);
        const { msg, text } = addMessage('assistant', '');
        text.classList.add('typing');
        const statusLine = el('div', 'msg-status');
        msg.prepend(statusLine);
        setStats({});
        $('s-tools').textContent = 'กำลังถาม AURA-AGENT…';

        const started = performance.now();
        let firstToken = null;

        try {
            const res = await api('/api/chat/stream', {
                method: 'POST',
                headers: { 'Accept': 'text/event-stream' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error(await errorMessage(res));

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });

                let cut;
                while ((cut = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, cut);
                    buffer = buffer.slice(cut + 2);

                    let event = 'message', data = '';
                    for (const line of block.split('\n')) {
                        if (line.startsWith('event: ')) event = line.slice(7);
                        else if (line.startsWith('data: ')) data += line.slice(6);
                    }
                    if (!data || data === '</stream>') continue;
                    const payload = JSON.parse(data);

                    if (event === 'status') {
                        statusLine.textContent = payload.text;
                    } else if (event === 'delta') {
                        firstToken ??= performance.now();
                        statusLine.remove();
                        text.textContent += payload.content;
                        $('messages').scrollTop = $('messages').scrollHeight;
                    } else if (event === 'done') {
                        currentSession = payload.session_id;
                        const tools = payload.tools_used ?? [];
                        setStats({
                            ttft: firstToken && firstToken - started,
                            total: performance.now() - started,
                            agent: payload.latency_ms,
                            tools: tools.length,
                        });
                        renderToolsPanel(tools);
                        renderToolsInline(msg, tools);
                    } else if (event === 'error') {
                        throw new Error(payload.message);
                    }
                }
            }
        } catch (err) {
            msg.classList.add('error');
            text.textContent = (text.textContent ? text.textContent + '\n\n' : '') + '⚠ ' + err.message;
        } finally {
            text.classList.remove('typing');
            statusLine.remove();
            busy = false;
            $('send').disabled = false;
            input.focus();
            loadSessions();
        }
    }

    // ---------- System status ----------
    async function loadStatus() {
        const box = $('status');
        box.textContent = 'กำลังตรวจสอบ…';
        const res = await api('/console/status');
        if (!res.ok) { box.textContent = await errorMessage(res); return; }
        const data = await res.json();
        const labels = { agent: 'AURA-AGENT', database: 'MySQL', ollama: 'Ollama (Open WebUI)' };
        box.replaceChildren();
        const grid = el('div', 'status-row');
        for (const [key, label] of Object.entries(labels)) {
            const s = data[key];
            if (!s) continue;
            grid.append(el('span', 'dot ' + (s.ok ? 'ok' : 'bad')));
            grid.append(el('span', 'status-name', `${label} · ${s.ok ? 'ปกติ' : 'ขัดข้อง'}`));
            grid.append(el('span', 'status-detail', s.detail));
        }
        box.append(grid);
    }

    // ---------- Wiring ----------
    function autosize() {
        const input = $('input');
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 180) + 'px';
    }

    $('composer').addEventListener('submit', (e) => { e.preventDefault(); send(); });
    $('input').addEventListener('input', autosize);
    $('input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
    });
    $('new-chat').onclick = newChat;

    renderEmpty();
    loadSessions();
    if (isAdmin) {
        $('refresh-status').onclick = loadStatus;
        loadStatus();
    }
})();
</script>
@endpush
