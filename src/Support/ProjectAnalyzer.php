<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectAnalyzer
{
    /**
     * @var string|null
     */
    private static ?string $memorySummary = null;

    public function summary(bool $fresh = false): string
    {
        if (! $fresh && self::$memorySummary !== null) {
            return self::$memorySummary;
        }

        if ($fresh || ! $this->cacheEnabled() || $this->usesDatabaseCacheStore()) {
            return self::$memorySummary = $this->buildSummary();
        }

        try {
            return self::$memorySummary = Cache::remember(
                $this->cacheKey(),
                $this->cacheTtl(),
                fn () => $this->buildSummary()
            );
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI project analysis cache unavailable; using runtime analysis.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return self::$memorySummary = $this->buildSummary();
        }
    }

    private function buildSummary(): string
    {
        $fileSignals = $this->fileSignals();
        $signals = $this->signals($fileSignals);
        $types = $this->applicationTypes($signals);
        $features = $this->features($signals);
        $areas = $this->businessAreas($fileSignals);

        $lines = [
            'Project Summary (internal only):',
            'Types:',
        ];

        foreach ($types as $type) {
            $lines[] = '- '.$type;
        }

        $lines[] = 'Features:';

        foreach ($features as $feature) {
            $lines[] = '- '.$feature;
        }

        $lines[] = 'Business Areas:';

        foreach ($areas as $area) {
            $lines[] = '- '.$area;
        }

        return $this->limitSummary(implode(PHP_EOL, $lines));
    }

    /**
     * @return array<int, string>
     */
    private function signals(array $fileSignals): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->composerSignals(),
            $fileSignals
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function fileSignals(): array
    {
        return $this->fileNameSignals([
            app_path('Models'),
            app_path('Http/Controllers'),
            app_path('Filament'),
            app_path('Livewire'),
            app_path('Services'),
            app_path('Policies'),
            app_path('Jobs'),
            app_path('Mail'),
            app_path('Notifications'),
            resource_path('views'),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function composerSignals(): array
    {
        $path = base_path('composer.json');

        if (! File::exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) File::get($path), true);

            if (! is_array($decoded)) {
                return [];
            }

            $packages = array_map('strtolower', array_merge(
                array_keys((array) ($decoded['require'] ?? [])),
                array_keys((array) ($decoded['require-dev'] ?? []))
            ));

            $signals = [];

            foreach ($packages as $package) {
                $packageName = trim((string) str($package)->afterLast('/')->replace(['-', '_'], ' '));

                if ($packageName !== '') {
                    $signals[] = $packageName;
                }
            }

            return array_values(array_unique($signals));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI composer analysis failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $directories
     * @return array<int, string>
     */
    private function fileNameSignals(array $directories): array
    {
        $maxItems = 80;
        $signals = [];

        foreach ($directories as $directory) {
            if (! is_string($directory) || ! File::isDirectory($directory)) {
                continue;
            }

            try {
                foreach (File::allFiles($directory) as $file) {
                    if (count($signals) >= $maxItems) {
                        break 2;
                    }

                    $extension = strtolower($file->getExtension());

                    if (! in_array($extension, ['php', 'blade.php'], true)) {
                        continue;
                    }

                    $signals[] = $this->humanize($file->getBasename('.'.$extension));
                }
            } catch (Throwable $e) {
                Log::warning('LaraKnow AI project directory analysis failed.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $signals;
    }

    /**
     * @param  array<int, string>  $signals
     * @return array<int, string>
     */
    private function applicationTypes(array $signals): array
    {
        return $this->configuredKeywordLabels(
            'laraknow.project_analysis.application_types',
            $signals,
            ['General Laravel Application'],
            4
        );
    }

    /**
     * @param  array<int, string>  $signals
     * @return array<int, string>
     */
    private function features(array $signals): array
    {
        return $this->configuredKeywordLabels(
            'laraknow.project_analysis.features',
            $signals,
            ['General customer support information'],
            $this->maxItems()
        );
    }

    /**
     * @param  array<int, string>  $signals
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function configuredKeywordLabels(string $configKey, array $signals, array $fallback, int $limit): array
    {
        $configured = [];

        if (! is_array($configured)) {
            return $fallback;
        }

        $text = $this->signalText($signals);
        $labels = [];

        foreach ($configured as $label => $keywords) {
            if (! is_string($label) || ! is_array($keywords)) {
                continue;
            }

            $keywords = array_values(array_filter(array_map(
                fn ($keyword): string => is_scalar($keyword) ? trim((string) $keyword) : '',
                $keywords
            )));

            if ($this->matchesAny($text, $keywords)) {
                $labels[] = $label;
            }
        }

        return array_slice($labels ?: $fallback, 0, $limit);
    }

    /**
     * @param  array<int, string>  $signals
     * @return array<int, string>
     */
    private function businessAreas(array $signals): array
    {
        $areas = [];

        foreach ($signals as $signal) {
            $signal = trim(preg_replace('/\b(controller|resource|policy|service|factory|seeder|migration|widget|page)\b/iu', '', $signal) ?? $signal);

            if ($signal === '' || mb_strlen($signal) < 3) {
                continue;
            }

            $areas[] = mb_convert_case($signal, MB_CASE_TITLE, 'UTF-8');
        }

        return array_slice(array_values(array_unique($areas)), 0, $this->maxItems());
    }

    /**
     * @param  array<int, string>  $signals
     */
    private function signalText(array $signals): string
    {
        return mb_strtolower(implode(' ', $signals));
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function matchesAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function humanize(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;
        $value = str_replace(['_', '-', '/', '\\', '.blade', '.php'], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function limitSummary(string $summary): string
    {
        $maxChars = 4000;

        return mb_strlen($summary) > $maxChars
            ? rtrim(mb_substr($summary, 0, $maxChars)).PHP_EOL.'- Summary truncated by configured limit.'
            : $summary;
    }

    private function maxItems(): int
    {
        return 12;
    }

    private function cacheEnabled(): bool
    {
        return true;
    }

    private function cacheTtl(): int
    {
        return 3600;
    }

    private function cacheKey(): string
    {
        return 'laraknow:project-analysis:'.sha1(implode('|', [
            base_path(),
            (string) @filemtime(base_path('composer.json')),
            json_encode([]),
        ]));
    }

    private function usesDatabaseCacheStore(): bool
    {
        $store = (string) config('cache.default');

        return (string) config("cache.stores.{$store}.driver") === 'database';
    }
}
