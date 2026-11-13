{{-- Generic A4 document: data built by App\Printing\PrintTemplate subclasses. --}}
@extends('print.layout')

@section('content')
<div class="sheet a4">
    @include('print.partials.letterhead')

    <div class="doc-title">
        <h1>{{ $title }}</h1>
        @if (filled($subtitle ?? null) && $subtitle !== $title)<div class="subtitle">{{ $subtitle }}</div>@endif
        @if (filled($status ?? null))<div class="status">{{ $status }}</div>@endif
    </div>

    <section class="info">
        <div class="box">
            <dl class="fields">
                @foreach ($fields as [$label, $value])
                    <div><dt>{{ $label }}:</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div>
    </section>

    @if (! empty($partyFields) || filled($qr ?? null))
        <section class="info">
            @if (! empty($partyFields))
                <div class="box">
                    @if (filled($partyTitle ?? null))<h3>{{ $partyTitle }}</h3>@endif
                    <dl class="fields single">
                        @foreach ($partyFields as [$label, $value])
                            <div><dt>{{ $label }}:</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
            @if (filled($qr ?? null))
                <div class="qr">
                    <div>
                        <div class="zatca-qr" data-qr="{{ $qr }}" data-size="110"></div>
                        @if (filled($uuid ?? null))<div class="uuid">{{ $uuid }}</div>@endif
                    </div>
                </div>
            @endif
        </section>
    @endif

    <table class="lines">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th class="{{ $column['align'] === 'end' ? 'end' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $key => $column)
                        <td class="{{ $column['align'] === 'end' ? 'end' : '' }}">{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align:center">{{ __('No lines') }}</td></tr>
            @endforelse
        </tbody>
        @if (! empty($footerRow))
            <tfoot>
                <tr>
                    @foreach ($columns as $key => $column)
                        <td class="{{ $column['align'] === 'end' ? 'end' : '' }}">{{ $footerRow[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if (! empty($totals) || filled($amountInWords ?? null))
        <section class="summary">
            <div class="words" @if (blank($amountInWords ?? null)) style="visibility:hidden" @endif>
                <strong>{{ __('Amount in Words') }}:</strong> {{ $amountInWords }}
            </div>
            @if (! empty($totals))
                <table class="totals">
                    @foreach ($totals as [$label, $value, $bold])
                        <tr class="{{ $bold ? 'bold' : '' }}"><td>{{ $label }}</td><td class="value">{{ $value }}</td></tr>
                    @endforeach
                </table>
            @endif
        </section>
    @endif

    @if (filled($notes ?? null))
        <div class="notes"><strong>{{ __('Notes') }}:</strong> {{ $notes }}</div>
    @endif

    @if (! empty($signatures))
        <section class="signatures">
            @foreach ($signatures as $signature)
                <div>{{ $signature }}</div>
            @endforeach
        </section>
    @endif

    <footer class="footer">
        <span>{{ __('Printed By') }}: {{ $printedBy }}</span>
        <span>{{ __('Print Date') }}: {{ $printedAt }}</span>
    </footer>
</div>
@endsection
