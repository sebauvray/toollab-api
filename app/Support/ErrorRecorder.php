<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Enregistre les erreurs serveur en base pour l'espace super-admin
 * (/admin/errors), regroupées par empreinte classe + fichier + ligne.
 *
 * Branché sur le gestionnaire d'exceptions (bootstrap/app.php) : seules les
 * exceptions réellement signalées passent ici, et l'écriture ne doit jamais
 * faire échouer la requête ni boucler sur elle-même.
 */
class ErrorRecorder
{
    private const IGNORED = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
    ];

    private const RETENTION_DAYS = 7;

    private static bool $recording = false;

    /** Job en cours d'exécution par le worker (renseigné par les événements de queue). */
    public static ?string $currentJob = null;

    /** Le job en cours envoie une notification ou un e-mail. */
    public static bool $currentJobIsMail = false;

    public static function record(Throwable $e): void
    {
        if (self::$recording || self::ignored($e)) {
            return;
        }

        self::$recording = true;
        try {
            self::write($e);
        } catch (Throwable $inner) {
            Log::warning('ErrorRecorder: écriture impossible', ['error' => $inner->getMessage()]);
        } finally {
            self::$recording = false;
        }
    }

    private static function ignored(Throwable $e): bool
    {
        foreach (self::IGNORED as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }

    private static function write(Throwable $e): void
    {
        [$file, $line] = self::appFrame($e);
        $fingerprint = sha1(get_class($e).'|'.$file.'|'.$line);
        $now = now();
        // Requête HTTP réelle = une route a été résolue (en console, request() existe mais sans route)
        $request = request()->route() ? request() : null;

        $values = [
            'category' => self::category($e, $request !== null),
            'exception_class' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 2000),
            'file' => $file,
            'line' => $line,
            'context' => self::context($request),
            'last_user_id' => $request?->user()?->id,
            'last_school_id' => $request && ctype_digit((string) $request->header('X-School-Id')) ? (int) $request->header('X-School-Id') : null,
            'last_trace' => mb_substr($e->getTraceAsString(), 0, 20000),
            'last_seen_at' => $now,
            // Une erreur résolue qui réapparaît est rouverte
            'resolved_at' => null,
        ];

        $updated = DB::table('error_groups')->where('fingerprint', $fingerprint)
            ->update($values + ['occurrences' => DB::raw('occurrences + 1')]);

        if (! $updated) {
            DB::table('error_groups')->insertOrIgnore($values + [
                'fingerprint' => $fingerprint,
                'occurrences' => 1,
                'first_seen_at' => $now,
            ]);
        }

        $groupId = DB::table('error_groups')->where('fingerprint', $fingerprint)->value('id');
        DB::table('error_events')->insert(['error_group_id' => $groupId, 'created_at' => $now]);

        // Pas de cron en production : purge au fil de l'eau
        if (random_int(1, 100) === 1) {
            DB::table('error_events')->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
        }
    }

    /** Première frame dans le code de l'application (hors vendor), à défaut celle de l'exception. */
    private static function appFrame(Throwable $e): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file && str_starts_with($file, $base) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                return [substr($file, strlen($base)), $frame['line'] ?? null];
            }
        }

        return [str_replace($base, '', $e->getFile()), $e->getLine()];
    }

    private static function category(Throwable $e, bool $http): string
    {
        if (self::$currentJobIsMail || $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            return 'mail';
        }
        if (self::$currentJob !== null) {
            return 'job';
        }

        return $http ? 'http' : 'console';
    }

    private static function context($request): ?string
    {
        if (self::$currentJob) {
            return 'Job '.self::$currentJob;
        }
        if ($request) {
            // Motif de route plutôt que l'URL : pas d'identifiants ni de paramètres de requête
            $uri = $request->route()->uri();

            return $request->method().' /'.ltrim($uri, '/');
        }

        return 'Console';
    }
}
