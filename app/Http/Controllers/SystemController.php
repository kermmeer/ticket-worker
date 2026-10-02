<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareSystemFolder;
use App\Models\System;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Systems', [
            'systems' => System::query()->orderBy('name')->get()->map(fn (System $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'branch' => $system->branch,
                'state' => $system->state,
                'error' => $system->error,
                'path' => $system->path(),
                'remote' => $system->pushRemote(),
                'head' => $system->state === System::READY ? $system->head() : null,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // It becomes a folder name: lower case, digits and dashes, nothing that climbs out.
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:systems,name'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\.|\/\/|\.lock$|\/$/'],
        ], [
            'name.regex' => 'Lower-case letters, digits and dashes only, starting with a letter or digit.',
            'branch.regex' => 'That is not a branch name git accepts.',
            'branch.not_regex' => 'That is not a branch name git accepts.',
        ]);

        PrepareSystemFolder::dispatch(System::create($data));

        return to_route('systems.index');
    }
}
