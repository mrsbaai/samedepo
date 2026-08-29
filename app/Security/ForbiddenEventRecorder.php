<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Models\ForbiddenEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ForbiddenEventRecorder
{
    public function record(HttpExceptionInterface $exception, Request $request): ?ForbiddenEvent
    {
        if ($exception->getStatusCode() !== 403) {
            return null;
        }

        $source = $request->attributes->get('forbidden.source') ?? $this->detectSource($exception);
        $reason = $request->attributes->get('forbidden.reason') ?? $exception->getMessage() ?: 'Forbidden';

        try {
            return ForbiddenEvent::query()->create([
                'source' => (string) $source,
                'reason' => $reason ? (string) $reason : null,
                'path' => '/'.$request->path(),
                'method' => $request->method(),
                'ip_address' => (string) $request->ip(),
                'user_id' => $request->user()?->id,
                'user_agent' => (string) $request->userAgent(),
                'threat_event_id' => $request->attributes->get('forbidden.threat_event_id'),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to record forbidden event', ['exception' => $e]);

            return null;
        }
    }

    private function detectSource(HttpExceptionInterface $exception): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            if (str_starts_with($file, $base.'app'.DIRECTORY_SEPARATOR)) {
                return str_replace($base, '', $file).':'.($frame['line'] ?? '?');
            }
        }

        return 'http_exception';
    }
}
