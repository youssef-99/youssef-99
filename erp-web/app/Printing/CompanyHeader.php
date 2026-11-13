<?php

namespace App\Printing;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Company letterhead data for printed documents (name AR/EN, CR, VAT no., address, phone, logo).
 */
class CompanyHeader
{
    public function __construct(
        public ?Company $company,
        public ?Branch $branch = null,
    ) {}

    public static function for(?Branch $branch = null): self
    {
        $company = $branch?->company ?? Company::query()->where('is_active', true)->orderBy('id')->first() ?? Company::query()->first();

        return new self($company, $branch);
    }

    public function nameAr(): string
    {
        return (string) ($this->company?->name_ar ?? config('app.name'));
    }

    public function nameEn(): ?string
    {
        return $this->company?->name_en;
    }

    public function commercialRegister(): ?string
    {
        return $this->company?->commercial_register;
    }

    public function vatNo(): ?string
    {
        return $this->company?->vat_no;
    }

    public function phone(): ?string
    {
        return $this->branch?->phone ?: ($this->company?->phone ?: $this->company?->mobile);
    }

    public function address(): ?string
    {
        if (! $this->company) {
            return $this->branch?->address;
        }

        $parts = array_filter([
            $this->company->building_no,
            $this->company->street_name,
            $this->company->district_name,
            $this->company->city_name,
            $this->company->postal_code,
        ], fn ($part) => filled($part));

        return $this->company->address ?: ($parts ? implode('، ', $parts) : $this->branch?->address);
    }

    public function branchName(): ?string
    {
        return $this->branch?->name;
    }

    /** Logo as a data URI (self-contained print page), or an absolute URL. */
    public function logo(): ?string
    {
        $logo = $this->company?->logo;

        if (blank($logo)) {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://') || str_starts_with($logo, 'data:')) {
            return $logo;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($logo)) {
                return null;
            }

            $mime = $disk->mimeType($logo) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($logo));
        } catch (Throwable) {
            return null;
        }
    }
}
