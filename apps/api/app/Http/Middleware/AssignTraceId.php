<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a new opaque trace identifier to each request and response.
 */
class AssignTraceId
{
    /**
     * Creates and propagates a server-controlled trace identifier without accepting client correlation values.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) Str::uuid();
        $request->attributes->set('trace_id', $traceId);
        Context::add('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
