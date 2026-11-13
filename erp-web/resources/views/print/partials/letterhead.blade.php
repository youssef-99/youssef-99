<header class="letterhead">
    <div class="side">
        <div class="name">{{ $company->nameAr() }}</div>
        @if ($company->branchName())<div class="meta">{{ __('Branch') }}: {{ $company->branchName() }}</div>@endif
        @if ($company->commercialRegister())<div class="meta">{{ __('Commercial Register') }}: {{ $company->commercialRegister() }}</div>@endif
        @if ($company->vatNo())<div class="meta">{{ __('VAT No.') }}: {{ $company->vatNo() }}</div>@endif
    </div>
    @if ($logo = $company->logo())
        <div class="logo"><img src="{{ $logo }}" alt="logo"></div>
    @endif
    <div class="side end">
        @if ($company->nameEn())<div class="name">{{ $company->nameEn() }}</div>@endif
        @if ($company->address())<div class="meta">{{ $company->address() }}</div>@endif
        @if ($company->phone())<div class="meta">{{ __('Phone') }}: {{ $company->phone() }}</div>@endif
    </div>
</header>
