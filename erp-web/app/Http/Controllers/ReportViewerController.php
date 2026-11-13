<?php

namespace App\Http\Controllers;

class ReportViewerController extends Controller
{
    public function show(string $report)
    {
        abort(404);
    }

    public function export(string $report, string $format)
    {
        abort(404);
    }
}
