@php
    use App\Reports\Support\ReportLang as RL;
    /** @var \App\Reports\ReportResult $result */
    $showPrice = (bool) ($result->layoutData['show_price'] ?? true);
    $currency = RL::get('SAR');
@endphp
{{-- Item barcode labels (طباعة باركود الأصناف). Barcodes are drawn in the browser with JsBarcode (CODE128). --}}
<style>
    .labels { display: grid; grid-template-columns: repeat(auto-fill, minmax(52mm, 1fr)); gap: 3mm; }
    .label { border: 1px dashed #9ca3af; padding: 2mm; text-align: center; page-break-inside: avoid; break-inside: avoid; overflow: hidden; }
    .label .name { font-weight: 700; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .label svg { max-width: 100%; height: 16mm; }
    .label .code { font-size: 10px; color: #374151; }
    .label .price { font-weight: 700; font-size: 12px; }
    .label .price small { font-weight: 400; color: #4b5563; }
    @media print {
        .company, .title, .info, .printed { display: none !important; }
        .label { border-color: #e5e7eb; }
    }
</style>
@if ($result->rows === [])
    <p class="empty">{{ RL::get('No data for the selected filters.') }}</p>
@endif
<div class="labels" id="report-labels">
    @foreach ($result->rows as $row)
        @php($cells = $row['cells'])
        @for ($copy = 0; $copy < max(1, (int) ($cells['copies'] ?? 1)); $copy++)
            <div class="label">
                <div class="name" title="{{ $cells['name'] }}">{{ $cells['name'] }}</div>
                <svg class="erp-barcode" data-value="{{ $cells['barcode'] }}"></svg>
                <div class="code" dir="ltr">{{ $cells['barcode'] }}</div>
                @if ($showPrice)
                    <div class="price" dir="ltr">{{ number_format((float) $cells['price_incl_vat'], 2) }} {{ $currency }} <small>{{ RL::get('incl. VAT') }}</small></div>
                @endif
            </div>
        @endfor
    @endforeach
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
<script>
    (function () {
        if (typeof JsBarcode === 'undefined') {
            return;
        }
        document.querySelectorAll('svg.erp-barcode').forEach(function (svg) {
            try {
                JsBarcode(svg, svg.dataset.value, {format: 'CODE128', displayValue: false, margin: 0, height: 50, width: 1.6});
            } catch (e) {
                svg.remove();
            }
        });
    })();
</script>
