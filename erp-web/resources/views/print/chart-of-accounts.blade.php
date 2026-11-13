{{-- Chart of accounts (طباعة دليل الحسابات): the account tree indented by level. --}}
@extends('print.layout')

@section('content')
<div class="sheet a4">
    @include('print.partials.letterhead')

    <div class="doc-title"><h1>{{ $title }}</h1></div>

    <table class="lines chart-of-accounts">
        <thead>
            <tr>
                <th>{{ __('Account No.') }}</th>
                <th>{{ __('Account Name') }}</th>
                <th>{{ __('Level No.') }}</th>
                <th>{{ __('Account Nature') }}</th>
                <th>{{ __('Balance Sheet Item') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tree as $node)
                @php($account = $node['account'])
                <tr style="{{ $node['depth'] === 0 ? 'font-weight:700;background:#f9fafb' : '' }}">
                    <td style="white-space:nowrap">{{ $account->number }}</td>
                    <td style="padding-inline-start: {{ 5 + $node['depth'] * 16 }}px">{{ $account->name }}</td>
                    <td class="end">{{ $account->level }}</td>
                    <td>{{ \App\Printing\Templates\ChartOfAccountsPrint::natureLabel($account->nature) }}</td>
                    <td>{{ filled($account->balance_sheet_item) ? __(\Illuminate\Support\Str::headline($account->balance_sheet_item)) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center">{{ __('No accounts') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer class="footer">
        <span>{{ __('Printed By') }}: {{ $printedBy }}</span>
        <span>{{ __('Print Date') }}: {{ $printedAt }}</span>
    </footer>
</div>
@endsection
