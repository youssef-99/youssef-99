<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}{{ filled($number ?? null) ? ' #'.$number : '' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #1f2937; --muted: #6b7280; --line: #d1d5db; --soft: #f3f4f6; --accent: #0f766e; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #e5e7eb; color: var(--ink); }
        body { font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 12px; line-height: 1.5; }
        .sheet { background: #fff; margin: 16px auto; padding: 14mm 12mm; }
        .sheet.a4 { width: 210mm; min-height: 297mm; }
        .toolbar { text-align: center; padding: 10px 0 0; }
        .toolbar button { font: inherit; padding: 6px 18px; border: 1px solid var(--accent); background: var(--accent); color: #fff; border-radius: 6px; cursor: pointer; }
        .letterhead { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 2px solid var(--accent); padding-bottom: 8px; margin-bottom: 10px; }
        .letterhead .side { flex: 1; }
        .letterhead .side.end { text-align: end; direction: ltr; }
        [dir="rtl"] .letterhead .side.end { text-align: left; }
        .letterhead .name { font-size: 16px; font-weight: 700; }
        .letterhead .meta { color: var(--muted); font-size: 11px; }
        .letterhead .logo img { max-height: 70px; max-width: 140px; }
        .doc-title { text-align: center; margin: 6px 0 12px; }
        .doc-title h1 { font-size: 18px; margin: 0; }
        .doc-title .subtitle { color: var(--muted); }
        .doc-title .status { display: inline-block; margin-top: 2px; padding: 0 10px; border: 1px solid var(--line); border-radius: 10px; font-size: 11px; }
        .info { display: flex; gap: 12px; margin-bottom: 10px; align-items: stretch; }
        .info .box { flex: 1; min-width: 0; border: 1px solid var(--line); border-radius: 6px; padding: 6px 10px; }
        .info .box h3 { margin: 0 0 4px; font-size: 12px; color: var(--accent); }
        .info .qr { flex: 0 0 auto; display: flex; align-items: center; justify-content: center; border: 1px solid var(--line); border-radius: 6px; padding: 6px; }
        dl.fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2px 14px; margin: 0; }
        dl.fields.single { grid-template-columns: minmax(0, 1fr); }
        dl.fields div { display: flex; gap: 6px; min-width: 0; }
        dl.fields dt { color: var(--muted); white-space: nowrap; }
        dl.fields dd { margin: 0; font-weight: 600; flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .uuid { font-size: 9px; color: var(--muted); direction: ltr; text-align: center; margin-top: 4px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.lines th, table.lines td { border: 1px solid var(--line); padding: 3px 5px; text-align: start; vertical-align: top; }
        table.lines th { background: var(--soft); font-weight: 600; font-size: 11px; }
        table.lines td.end, table.lines th.end { text-align: end; white-space: nowrap; }
        table.lines tfoot td { font-weight: 700; background: var(--soft); }
        .summary { display: flex; gap: 12px; align-items: flex-start; }
        .summary .words { flex: 1; border: 1px dashed var(--line); border-radius: 6px; padding: 6px 10px; }
        table.totals { border-collapse: collapse; min-width: 75mm; }
        table.totals td { border: 1px solid var(--line); padding: 3px 8px; }
        table.totals td.value { text-align: end; white-space: nowrap; font-weight: 600; }
        table.totals tr.bold td { background: var(--soft); font-weight: 700; font-size: 13px; }
        .notes { margin-top: 10px; border: 1px solid var(--line); border-radius: 6px; padding: 6px 10px; white-space: pre-line; }
        .signatures { display: flex; gap: 24px; margin-top: 36px; }
        .signatures div { flex: 1; text-align: center; border-top: 1px solid var(--ink); padding-top: 4px; }
        .footer { margin-top: 18px; color: var(--muted); font-size: 10px; display: flex; justify-content: space-between; }
        .zatca-qr { min-width: 60px; }
        .zatca-qr.qr-fallback { max-width: 40mm; font-size: 7px; line-height: 1.2; word-break: break-all; direction: ltr; }
        @page { size: A4; margin: 10mm; }
        @media print {
            html, body { background: #fff; }
            .sheet, .sheet.a4 { margin: 0; padding: 0; width: auto; min-height: 0; box-shadow: none; }
            .toolbar { display: none; }
            table.lines thead { display: table-header-group; }
            table.lines tr { page-break-inside: avoid; }
        }
        @stack('styles')
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
    @yield('content')

    @if (filled($qr ?? null))
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            document.querySelectorAll('[data-qr]').forEach(function (el) {
                if (typeof QRCode === 'undefined') { el.classList.add('qr-fallback'); el.textContent = el.dataset.qr; return; }
                new QRCode(el, { text: el.dataset.qr, width: parseInt(el.dataset.size || '120', 10), height: parseInt(el.dataset.size || '120', 10), correctLevel: QRCode.CorrectLevel.M });
            });
        </script>
    @endif
    @if ($autoprint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
