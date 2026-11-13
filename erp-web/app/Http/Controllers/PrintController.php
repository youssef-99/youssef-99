<?php

namespace App\Http\Controllers;

use App\Printing\CompanyHeader;
use App\Printing\PrintPermissions;
use App\Printing\PrintRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Printable documents: `/print/{type}/{id}` (types in PrintRegistry::TEMPLATES).
 *
 * Query options: `?autoprint=1` opens the print dialog, `?layout=80mm` thermal receipt (sales).
 * Access: the user must be able to view a page that lists the record (admins bypass).
 */
class PrintController extends Controller
{
    public function show(Request $request, string $type, string $id): Response
    {
        $template = PrintRegistry::resolve($type);

        abort_if($template === null || ! ctype_digit($id), 404);

        $record = $template->find((int) $id);

        abort_unless(PrintPermissions::allows($request->user(), $template->permissionKeys($record)), 403);

        $locale = app()->getLocale();

        return response()->view($template->view($record, $request), [
            ...$template->build($record, $request),
            'company' => CompanyHeader::for($template->branch($record)),
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'autoprint' => $request->boolean('autoprint'),
            'printedAt' => now()->format('Y-m-d H:i'),
            'printedBy' => $request->user()?->getFilamentName(),
        ]);
    }
}
