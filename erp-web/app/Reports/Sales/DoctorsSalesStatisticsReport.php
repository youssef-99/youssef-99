<?php

namespace App\Reports\Sales;

use App\Models\Doctor;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Doctors Sales Statistics (إحصائيات مبيعات الأطباء): sales of prescriptions linked to a doctor.
 * Summary: per doctor — invoices, quantity, net sales (excl. VAT), VAT and total. Detail: per doctor, one row per item.
 * Filters: Branches, Report Type*, Doctor, From*, To*.
 */
class DoctorsSalesStatisticsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'doctors-sales-statistics';

    protected static string $title = 'Doctors Sales Statistics';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::reportType()->required(),
            TradeFields::doctor(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['report_type' => 'summary', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $detail = ($filters['report_type'] ?? 'summary') === 'detail';
        $query = SalesQuery::lines($filters)
            ->whereNotNull('d.doctor_id')
            ->groupBy('d.doctor_id')
            ->select('d.doctor_id')
            ->selectRaw('COUNT(DISTINCT d.id) as documents')
            ->selectRaw('SUM('.SalesQuery::lineQty().') as quantity')
            ->selectRaw('SUM('.SalesQuery::lineAmount(false).') as net')
            ->selectRaw('SUM('.SalesQuery::lineTax().') as vat')
            ->selectRaw('SUM('.SalesQuery::lineAmount(true).') as total');
        if ($detail) {
            $query->addSelect(['i.code', 'i.name_ar', 'i.name_en'])->groupBy('i.id', 'i.code', 'i.name_ar', 'i.name_en')->orderBy('i.code');
        }
        $rows = $query->get();
        $doctors = Doctor::query()->whereKey($rows->pluck('doctor_id')->unique()->all())->orderBy('code')->get();
        $keys = ['documents', 'quantity', 'net', 'vat', 'total'];

        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns([
                ReportColumn::text('code', $detail ? 'Item Code' : 'Code'),
                ReportColumn::text('name', $detail ? 'Item Name' : 'Doctor'),
                ReportColumn::number('documents', 'Invoices'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::money('net', 'Net Sales'),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('total', 'Grand Total'),
            ]);

        $byDoctor = $rows->groupBy('doctor_id');
        foreach ($doctors as $doctor) {
            if ($detail) {
                $result->addGroup($doctor->label);
            }
            $cells = [];
            foreach ($byDoctor->get($doctor->id, collect()) as $r) {
                $cells[] = $cell = [
                    'code' => $detail ? $r->code : $doctor->code,
                    'name' => $detail ? Ledger::localName($r->name_ar, $r->name_en) : $doctor->name,
                    'documents' => (int) $r->documents,
                    'quantity' => round((float) $r->quantity, 3),
                    'net' => round((float) $r->net, 2),
                    'vat' => round((float) $r->vat, 2),
                    'total' => round((float) $r->total, 2),
                ];
                $result->addRow($cell);
            }
            if ($detail) {
                $result->addSubtotal(['name' => static::t('Doctor Total')] + ReportTotals::sum($cells, ['quantity', 'net', 'vat', 'total']));
            }
        }

        return ReportTotals::footer($result, $detail ? ['quantity', 'net', 'vat', 'total'] : $keys, 'name');
    }
}
