<?php

namespace App\Http\Controllers;

class PrintController extends Controller
{
    public function show(string $type, int $id)
    {
        abort(404);
    }
}
