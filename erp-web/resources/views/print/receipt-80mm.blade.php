{{-- 80mm thermal receipt for POS / cash sales (?layout=80mm). --}}
@extends('print.layout')

@push('styles')
    .sheet.receipt { width: 80mm; padding: 4mm 3mm; font-size: 11px; }
    .receipt .center { text-align: center; }
    .receipt .name { font-size: 14px; font-weight: 700; }
    .receipt .muted { color: var(--muted); font-size: 10px; }
    .receipt hr { border: 0; border-top: 1px dashed var(--ink); margin: 5px 0; }
    .receipt table { width: 100%; border-collapse: collapse; }
    .receipt table td, .receipt table th { padding: 1px 0; text-align: start; vertical-align: top; }
    .receipt table .end { text-align: end; white-space: nowrap; }
    .receipt .grand td { font-size: 13px; font-weight: 700; }
    .receipt .qr { display: flex; justify-content: center; margin: 6px 0; }
    @page { size: 80mm auto; margin: 0; }
    @media print { .sheet.receipt { width: 80mm; padding: 2mm 3mm; } }
@endpush

@section('content')
<div class="sheet receipt layout-80mm">
    <div class="center">
        @if ($logo = $company->logo())<img src="{{ $logo }}" alt="logo" style="max-height:40px"><br>@endif
        <div class="name">{{ $company->nameAr() }}</div>
        @if ($company->nameEn())<div>{{ $company->nameEn() }}</div>@endif
        @if ($company->branchName())<div class="muted">{{ $company->branchName() }}</div>@endif
        @if ($company->address())<div class="muted">{{ $company->address() }}</div>@endif
        @if ($company->phone())<div class="muted">{{ __('Phone') }}: {{ $company->phone() }}</div>@endif
        @if ($company->vatNo())<div>{{ __('VAT No.') }}: {{ $company->vatNo() }}</div>@endif
        @if ($company->commercialRegister())<div class="muted">{{ __('Commercial Register') }}: {{ $company->commercialRegister() }}</div>@endif
    </div>
    <hr>
    <div class="center"><strong>{{ $title }}</strong></div>
    <table>
        <tr><td>{{ __('Invoice No.') }}</td><td class="end">{{ $record->number }}</td></tr>
        <tr><td>{{ __('Date') }}</td><td class="end">{{ $record->date?->format('Y-m-d') }} {{ $record->time ? substr((string) $record->time, 0, 5) : '' }}</td></tr>
        @foreach ($partyFields as [$label, $value])
            <tr><td>{{ $label }}</td><td class="end">{{ $value }}</td></tr>
        @endforeach
    </table>
    <hr>
    <table>
        <thead>
            <tr><th>{{ __('Item') }}</th><th class="end">{{ __('Qty') }}</th><th class="end">{{ __('Price') }}</th><th class="end">{{ __('Total') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr><td colspan="4">{{ $row['name'] }}</td></tr>
                <tr><td class="muted">{{ $row['batch'] }}</td><td class="end">{{ $row['quantity'] }}</td><td class="end">{{ $row['price'] }}</td><td class="end">{{ $row['net'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <hr>
    <table>
        @foreach ($totals as [$label, $value, $bold])
            <tr class="{{ $bold ? 'grand' : '' }}"><td>{{ $label }}</td><td class="end">{{ $value }}</td></tr>
        @endforeach
    </table>
    @if (filled($qr ?? null))
        <div class="qr"><div class="zatca-qr" data-qr="{{ $qr }}" data-size="140"></div></div>
    @endif
    <div class="center muted">{{ __('Prices include VAT') }}</div>
    <div class="center">{{ __('Thank you for your visit') }}</div>
    <div class="center muted">{{ $printedAt }}</div>
</div>
@endsection
