<x-filament-panels::page>
    <style>
        .erp-reports-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); align-items: start; }
        .erp-reports-list { list-style: none; margin: 0; padding: 0; }
        .erp-reports-list li { padding: .35rem 0; border-bottom: 1px dashed rgba(127, 127, 127, .2); }
        .erp-reports-list li:last-child { border-bottom: 0; }
        .erp-reports-list a { display: flex; gap: .5rem; align-items: center; }
        .erp-reports-list a:hover { text-decoration: underline; }
        .erp-reports-disabled { opacity: .5; cursor: not-allowed; display: flex; gap: .5rem; align-items: center; }
        .erp-reports-soon { font-size: .7rem; border: 1px solid currentColor; border-radius: .5rem; padding: 0 .4rem; }
    </style>

    @if ($groups === [])
        <x-filament::section>
            {{ \App\Reports\Support\ReportLang::get('No reports available.') }}
        </x-filament::section>
    @else
        <div class="erp-reports-grid">
            @foreach ($groups as $groupKey => $node)
                <x-filament::section :icon="$node['icon']" id="group-{{ $groupKey }}">
                    <x-slot name="heading">{{ $node['label'] }}</x-slot>
                    <ul class="erp-reports-list">
                        @foreach ($node['reports'] as $item)
                            <li>
                                @if ($item['implemented'])
                                    <a href="{{ $urlFor($item['key']) }}" class="text-primary-600 dark:text-primary-400">
                                        <x-filament::icon icon="heroicon-m-document-text" class="h-4 w-4" style="width:1rem;height:1rem" />
                                        <span>{{ $item['title'] }}</span>
                                    </a>
                                @else
                                    <span class="erp-reports-disabled" title="{{ \App\Reports\Support\ReportLang::get('Coming soon') }}">
                                        <x-filament::icon icon="heroicon-m-document" class="h-4 w-4" style="width:1rem;height:1rem" />
                                        <span>{{ $item['title'] }}</span>
                                        <span class="erp-reports-soon">{{ \App\Reports\Support\ReportLang::get('Coming soon') }}</span>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
