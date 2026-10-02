<?php

namespace App\Http\Controllers;

use App\Setup\SetupChecks;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function __invoke(SetupChecks $checks): Response
    {
        return Inertia::render('Setup', ['checks' => $checks->all()]);
    }
}
