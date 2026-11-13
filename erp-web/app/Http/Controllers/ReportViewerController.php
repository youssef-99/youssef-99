<?php

namespace App\Http\Controllers;

use App\Exceptions\ErpException;
use App\Models\Company;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Support\SafeSpreadsheetRow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report viewer (opens in a new tab): standalone page with the company header, the report table and a toolbar
 * (print, XLSX / CSV export, search, zoom). Filters arrive as the query string and are validated with the
 * report's rules. Export cells go through {@see SafeSpreadsheetRow} so text cannot become a formula. Access needs "reports.<group>.<report>.view".
 */
class ReportViewerController extends Controller
{
    public function show(Request $request, string $report): View|Response
    {
        $instance = $this->resolve($report);
        [$filters, $errors] = $this->filters($request, $instance);

        if ($errors !== []) {
            return response()->view('reports.viewer', $this->viewData($request, $report, null, $errors), 422);
        }

        try {
            $result = $instance->run($filters);
        } catch (ErpException $e) {
            return response()->view('reports.viewer', $this->viewData($request, $report, null, [$e->getMessage()]), 422);
        }

        return view('reports.viewer', $this->viewData($request, $report, $result, []));
    }

    public function export(Request $request, string $report, string $format): BinaryFileResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);
        $instance = $this->resolve($report);
        [$filters, $errors] = $this->filters($request, $instance);
        abort_if($errors !== [], 422, implode(' ', $errors));

        $result = $instance->run($filters);
        $base = (string) tempnam(sys_get_temp_dir(), 'report');
        @unlink($base);
        $path = $base.'.'.$format;

        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path); // the CSV writer adds a UTF-8 BOM so Excel reads Arabic correctly
        if ($format === 'xlsx') {
            $writer->getCurrentSheet()->setName(Str::limit(preg_replace('/[\\\\\/?*:\[\]]/u', ' ', $result->title) ?: 'Report', 28, ''));
        }
        foreach ($result->toMatrix() as $cells) {
            $writer->addRow(SafeSpreadsheetRow::fromValues($cells));
        }
        $writer->close();

        $name = Str::slug(str_replace('/', '-', $report)).'-'.now()->format('Ymd-His').'.'.$format;

        return response()->download($path, $name, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend();
    }

    protected function resolve(string $key): Report
    {
        abort_unless(ReportRegistry::exists($key), 404);
        $instance = ReportRegistry::make($key);
        abort_unless($instance !== null, 404);
        abort_unless(ReportRegistry::canView($key), 403);

        return $instance;
    }

    /**
     * Query string merged over the report defaults, then validated.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    protected function filters(Request $request, Report $report): array
    {
        $input = array_merge($report->defaults(), array_filter($request->query(), fn ($v) => $v !== null && $v !== ''));
        $validator = Validator::make($input, $report->rules(), [], $report->attributeLabels());

        if ($validator->fails()) {
            return [$input, $validator->errors()->all()];
        }

        return [$input, []];
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    protected function viewData(Request $request, string $key, ?ReportResult $result, array $errors): array
    {
        $company = auth()->user()?->defaultBranch?->company ?? Company::query()->first();
        $query = $request->query();

        return [
            'key' => $key,
            'title' => $result?->title ?? ReportRegistry::title($key),
            'result' => $result,
            'reportErrors' => $errors,
            'company' => $company,
            'logoUrl' => $this->logoUrl($company?->logo),
            'rtl' => app()->getLocale() === 'ar',
            'xlsxUrl' => route('report.export', ['report' => $key, 'format' => 'xlsx'] + $query),
            'csvUrl' => route('report.export', ['report' => $key, 'format' => 'csv'] + $query),
            'printedAt' => now(),
        ];
    }

    protected function logoUrl(?string $logo): ?string
    {
        if (blank($logo)) {
            return null;
        }
        if (Str::startsWith($logo, ['http://', 'https://', 'data:', '/'])) {
            return $logo;
        }

        return Storage::disk('public')->url($logo);
    }
}
