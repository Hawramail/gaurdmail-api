<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    // Pure API — never redirect to a login page; return null so the parent
    // throws AuthenticationException cleanly and our bootstrap handler returns 401.
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
