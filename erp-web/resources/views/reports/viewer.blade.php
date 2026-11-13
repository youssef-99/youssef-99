@php
    use App\Reports\Support\ReportLang as RL;
    /** @var \App\Reports\ReportResult|null $result */
    $columnCount = $result ? max(1, count($result->columns)) : 1;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        :root { --border: #9ca3af; --head: #e5f3f1; --band: #cfe9e5; --sub: #f3f4f6; --accent: #0f766e; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #111827; font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 13px; }
        .toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; padding: .5rem 1rem; background: #1f2937; color: #fff; }
        .toolbar button, .toolbar a { display: inline-flex; align-items: center; gap: .3rem; padding: .35rem .75rem; border: 0; border-radius: .375rem; background: #374151; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
        .toolbar button:hover, .toolbar a:hover { background: var(--accent); }
        .toolbar .primary { background: var(--accent); }
        .toolbar input[type=search] { padding: .35rem .5rem; border-radius: .375rem; border: 0; min-width: 12rem; font: inherit; }
        .toolbar .spacer { flex: 1; }
        .toolbar label { display: inline-flex; gap: .25rem; align-items: center; }
        .zoom-value { min-width: 3.5rem; text-align: center; }
        .page { margin: 1rem auto; padding: 1.25rem 1.5rem; max-width: 1100px; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .2); transform-origin: top center; }
        .page.landscape { max-width: 1500px; }
        .company { display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid var(--accent); padding-bottom: .75rem; }
        .company h1 { margin: 0 0 .25rem; font-size: 1.25rem; color: var(--accent); }
        .company dl { display: grid; grid-template-columns: repeat(2, auto); gap: .1rem 1.5rem; margin: 0; font-size: 12px; }
        .company dt { display: inline; color: #4b5563; }
        .company dd { display: inline; margin: 0 .5rem 0 .25rem; }
        .company img { max-height: 80px; max-width: 160px; object-fit: contain; }
        .title { text-align: center; margin: .75rem 0 .25rem; }
        .title h2 { margin: 0; font-size: 1.15rem; }
        .title .subtitle { color: #4b5563; }
        .info { display: flex; flex-wrap: wrap; justify-content: center; gap: .25rem 1.5rem; margin-bottom: .75rem; font-size: 12px; }
        .info b { color: #374151; }
        table.report { width: 100%; border-collapse: collapse; }
        table.report th, table.report td { border: 1px solid var(--border); padding: .25rem .4rem; vertical-align: top; }
        table.report thead th { background: var(--head); font-weight: 700; text-align: center; }
        table.report thead tr.bands th { background: var(--band); }
        table.report td.num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.report td.date { white-space: nowrap; text-align: center; }
        table.report td.neg { color: #b91c1c; }
        table.report tr.group td { background: var(--band); font-weight: 700; }
        table.report tr.subtotal td { background: var(--sub); font-weight: 700; }
        table.report tr.opening td { font-style: italic; background: #fafafa; }
        table.report tfoot td { background: var(--head); font-weight: 700; }
        table.report tbody tr:hover td { background: #fef9c3; }
        mark { background: #fde047; padding: 0; }
        tr.search-hidden { display: none; }
        .notes { margin-top: .75rem; font-size: 12px; color: #374151; }
        .printed { margin-top: 1rem; display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; }
        .errors { border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; padding: .75rem 1rem; border-radius: .375rem; }
        .empty { text-align: center; color: #6b7280; padding: 1rem; }
        @media print {
            @page { size: A4 {{ $result?->landscape ? 'landscape' : 'portrait' }}; margin: 10mm; }
            body { background: #fff; font-size: 11px; }
            .toolbar { display: none; }
            .page { margin: 0; padding: 0; max-width: none; box-shadow: none; transform: none !important; }
            table.report thead { display: table-header-group; }
            table.report tfoot { display: table-row-group; }
            table.report tr { page-break-inside: avoid; break-inside: avoid; }
            tr.search-hidden { display: table-row; }
            mark { background: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">&#128424; {{ RL::get('Print') }}</button>
    @if ($result)
        <a href="{{ $xlsxUrl }}">&#8681; {{ RL::get('Export XLSX') }}</a>
        <a href="{{ $csvUrl }}">&#8681; {{ RL::get('Export CSV') }}</a>
        <input type="search" id="report-search" placeholder="{{ RL::get('Search') }}..." aria-label="{{ RL::get('Search') }}">
        <label><input type="checkbox" id="report-filter-rows"> {{ RL::get('Matching rows only') }}</label>
        <span id="report-search-count"></span>
    @endif
    <span class="spacer"></span>
    <button type="button" onclick="erpZoom(-0.1)" title="{{ RL::get('Zoom Out') }}">&minus;</button>
    <span class="zoom-value" id="zoom-value">100%</span>
    <button type="button" onclick="erpZoom(0.1)" title="{{ RL::get('Zoom In') }}">+</button>
    <button type="button" onclick="window.close()">{{ RL::get('Close') }}</button>
</div>

<div class="page {{ $result?->landscape ? 'landscape' : '' }}" id="report-page">
    <header class="company">
        <div>
            <h1>{{ $company?->name }}</h1>
            <dl>
                @foreach ([
                    'Commercial Register' => $company?->commercial_register,
                    'Membership No.' => $company?->membership_no,
                    'Phone' => $company?->phone,
                    'Fax' => $company?->fax,
                    'Email' => $company?->email,
                    'Postal Code' => $company?->postal_code,
                    'Tax No.' => $company?->vat_no,
                ] as $label => $value)
                    @if (filled($value))
                        <div><dt>{{ RL::get($label) }}:</dt><dd>{{ $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
        </div>
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $company?->name }}">
        @endif
    </header>

    <div class="title">
        <h2>{{ $title }}</h2>
        @if ($result?->subtitle)
            <div class="subtitle">{{ $result->subtitle }}</div>
        @endif
    </div>

    @if ($reportErrors)
        <div class="errors">
            <strong>{{ RL::get('The report could not be shown:') }}</strong>
            <ul>
                @foreach ($reportErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($result)
        @if ($result->info)
            <div class="info">
                @foreach ($result->info as $label => $value)
                    <span><b>{{ $label }}:</b> {{ $value }}</span>
                @endforeach
            </div>
        @endif

        <table class="report" id="report-table">
            <thead>
            @if ($result->hasColumnGroups())
                <tr class="bands">
                    @foreach ($result->headerBands() as $band)
                        <th colspan="{{ $band['span'] }}" @if (! $band['grouped']) rowspan="2" @endif>{{ $band['label'] }}</th>
                    @endforeach
                </tr>
                <tr>
                    @foreach ($result->columns as $column)
                        @if ($column->group !== null)
                            <th>{{ $column->label }}</th>
                        @endif
                    @endforeach
                </tr>
            @else
                <tr>
                    @foreach ($result->columns as $column)
                        <th>{{ $column->label }}</th>
                    @endforeach
                </tr>
            @endif
            </thead>
            <tbody>
            @forelse ($result->rows as $row)
                @if ($row['type'] === 'group')
                    <tr class="group"><td colspan="{{ $columnCount }}">{{ $row['label'] }}</td></tr>
                @else
                    <tr class="{{ $row['type'] }}">
                        @foreach ($result->columns as $column)
                            @php($value = $row['cells'][$column->key] ?? null)
                            <td class="{{ $column->isNumeric() ? 'num' : '' }} {{ $column->type === 'date' ? 'date' : '' }} {{ $column->isNumeric() && is_numeric($value) && $value < 0 ? 'neg' : '' }}" @if ($column->isNumeric()) dir="ltr" @endif>{{ $column->format($value) }}</td>
                        @endforeach
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $columnCount }}" class="empty">{{ RL::get('No data for the selected filters.') }}</td></tr>
            @endforelse
            </tbody>
            @if ($result->footer)
                <tfoot>
                @foreach ($result->footer as $row)
                    <tr>
                        @foreach ($result->columns as $column)
                            @php($value = $row['cells'][$column->key] ?? null)
                            <td class="{{ $column->isNumeric() ? 'num' : '' }}" @if ($column->isNumeric()) dir="ltr" @endif>{{ $column->format($value) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tfoot>
            @endif
        </table>

        @if ($result->notes)
            <div class="notes">
                @foreach ($result->notes as $note)
                    <p>{{ $note }}</p>
                @endforeach
            </div>
        @endif
    @endif

    <div class="printed">
        <span>{{ RL::get('Printed by') }}: {{ auth()->user()?->getFilamentName() }}</span>
        <span>{{ RL::get('Print Date') }}: {{ $printedAt->format('d/m/Y H:i') }}</span>
    </div>
</div>

<script>
    (function () {
        let zoom = 1;
        window.erpZoom = function (delta) {
            zoom = Math.min(2, Math.max(0.5, Math.round((zoom + delta) * 10) / 10));
            document.getElementById('report-page').style.transform = 'scale(' + zoom + ')';
            document.getElementById('zoom-value').textContent = Math.round(zoom * 100) + '%';
        };

        const input = document.getElementById('report-search');
        const filterRows = document.getElementById('report-filter-rows');
        const count = document.getElementById('report-search-count');
        const table = document.getElementById('report-table');
        if (!input || !table) {
            return;
        }
        const cells = Array.from(table.querySelectorAll('tbody td'));
        cells.forEach(function (td) { td.dataset.text = td.textContent; });

        function escapeHtml(text) {
            return text.replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; });
        }

        function run() {
            const term = input.value.trim().toLowerCase();
            let matches = 0;
            cells.forEach(function (td) {
                const text = td.dataset.text;
                const index = term ? text.toLowerCase().indexOf(term) : -1;
                if (index >= 0) {
                    matches++;
                    td.innerHTML = escapeHtml(text.slice(0, index)) + '<mark>' + escapeHtml(text.slice(index, index + term.length)) + '</mark>' + escapeHtml(text.slice(index + term.length));
                } else if (td.innerHTML !== escapeHtml(text)) {
                    td.textContent = text;
                }
            });
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                const hide = term && filterRows.checked && !tr.querySelector('mark') && !tr.classList.contains('group');
                tr.classList.toggle('search-hidden', !!hide);
            });
            count.textContent = term ? matches + ' {{ RL::get('matches') }}' : '';
            const first = table.querySelector('mark');
            if (first) {
                first.scrollIntoView({block: 'center', behavior: 'smooth'});
            }
        }

        input.addEventListener('input', run);
        filterRows.addEventListener('change', run);
    })();
</script>
</body>
</html>
