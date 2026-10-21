<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Aura') · Aura</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f7f9;
            --surface: #ffffff;
            --surface-2: #f0f2f5;
            --border: #e2e5ea;
            --text: #1b1f24;
            --muted: #5f6b7a;
            --accent: #5b3fd1;
            --accent-hover: #4a2fbf;
            --accent-soft: #eeeafd;
            --on-accent: #ffffff;
            --ok: #1a7f4b;
            --bad: #c0392b;
            --warn: #a15c00;
            --radius: 12px;
            --shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 4px 16px rgba(16, 24, 40, .06);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1115;
                --surface: #171a20;
                --surface-2: #1f232b;
                --border: #2b313b;
                --text: #e7eaee;
                --muted: #9aa4b2;
                --accent: #9d87ff;
                --accent-hover: #b3a2ff;
                --accent-soft: #2a2546;
                --on-accent: #14111f;
                --ok: #4cc38a;
                --bad: #ff7b6e;
                --warn: #f0b35a;
                --shadow: 0 1px 2px rgba(0, 0, 0, .4), 0 4px 16px rgba(0, 0, 0, .3);
            }
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.6 "IBM Plex Sans Thai", system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--accent); }
        button, input, textarea { font: inherit; color: inherit; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--surface); cursor: pointer; text-decoration: none; color: var(--text);
            font-weight: 500; transition: background .15s, border-color .15s;
        }
        .btn:hover { background: var(--surface-2); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        .btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .btn-sm { padding: 6px 10px; font-size: 13px; }
        .field { display: grid; gap: 6px; }
        .field label { font-size: 13px; color: var(--muted); font-weight: 500; }
        .input {
            width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--surface); outline: none;
        }
        .input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .muted { color: var(--muted); }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 600; letter-spacing: .2px; }
        .brand-mark {
            width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center;
            background: var(--accent); color: var(--on-accent); font-weight: 600; font-size: 15px;
        }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
