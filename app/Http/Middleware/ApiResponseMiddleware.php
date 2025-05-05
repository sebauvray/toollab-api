<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $statusCode = $response->getStatusCode();
        $data = $response->getData(true);

        if ($statusCode >= 400) {
            return $response;
        }

        $formattedResponse = [
            'status' => $this->getStatusType($statusCode),
            'message' => $this->getDefaultMessage($statusCode),
            'data' => $data
        ];

        return $response->setData($formattedResponse);
    }

    /**
     * Get status type based on HTTP code
     */
    private function getStatusType(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return 'success';
        }
        if ($statusCode >= 400 && $statusCode < 500) {
            return 'error';
        }
        if ($statusCode >= 500) {
            return 'error';
        }
        return 'unknown';
    }

    /**
     * Get default message based on HTTP code
     */
    private function getDefaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            200 => 'Operation successful',
            201 => 'Resource created successfully',
            default => 'An error has occurred'
        };
    }
}
