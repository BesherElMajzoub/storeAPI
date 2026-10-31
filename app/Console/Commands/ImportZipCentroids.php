<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportZipCentroids extends Command
{
    protected $signature = 'geo:import-zip-centroids {--file= : A local Census ZCTA gazetteer .zip or .txt instead of downloading it}';

    protected $description = 'Load the centre point of every US ZIP code (Census ZCTA gazetteer) used to favour nearby address suggestions';

    public function handle(): int
    {
        $workDir = storage_path('app/geo');
        if (! is_dir($workDir)) {
            mkdir($workDir, 0775, true);
        }

        $source = $this->option('file');
        $downloaded = null;

        if (! $source) {
            $downloaded = $workDir.'/zcta-'.uniqid().'.zip';
            $response = Http::timeout(120)->withOptions(['sink' => $downloaded])->get(config('services.geo.zip_source_url'));
            if ($response->failed()) {
                @unlink($downloaded);
                $this->error('Could not download the ZIP gazetteer (HTTP '.$response->status().').');

                return self::FAILURE;
            }
            $source = $downloaded;
        }

        try {
            $lines = $this->readLines($source);
            if ($lines === null) {
                $this->error('The gazetteer file could not be read.');

                return self::FAILURE;
            }

            $count = $this->import($lines);
        } finally {
            if ($downloaded) {
                @unlink($downloaded);
            }
        }

        if ($count === 0) {
            $this->error('No ZIP rows found; the table was left as it was.');

            return self::FAILURE;
        }

        $this->info("Imported {$count} ZIP centres.");

        return self::SUCCESS;
    }

    /** @return iterable<string>|null */
    private function readLines(string $path): ?iterable
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            return is_file($path) ? new \SplFileObject($path) : null;
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true || $zip->numFiles < 1) {
            return null;
        }
        $text = $zip->getFromIndex(0);
        $zip->close();

        return $text === false ? null : explode("\n", $text);
    }

    /** @param  iterable<string>  $lines */
    private function import(iterable $lines): int
    {
        $rows = [];
        $count = 0;

        // Keep the old data until the new file is known to be good.
        DB::transaction(function () use ($lines, &$rows, &$count) {
            $seen = [];
            foreach ($lines as $line) {
                // Columns: GEOID, ALAND, AWATER, ALAND_SQMI, AWATER_SQMI, INTPTLAT, INTPTLONG
                $cols = array_map('trim', explode("\t", (string) $line));
                if (count($cols) < 7 || ! preg_match('/^\d{5}$/', $cols[0]) || ! is_numeric($cols[5]) || ! is_numeric($cols[6])) {
                    continue;
                }
                $seen[$cols[0]] = ['zip' => $cols[0], 'lat' => (float) $cols[5], 'lng' => (float) $cols[6]];
            }

            if ($seen === []) {
                return;
            }

            DB::table('zip_centroids')->delete();
            foreach (array_chunk(array_values($seen), 1000) as $chunk) {
                DB::table('zip_centroids')->insert($chunk);
            }
            $count = count($seen);
        });

        return $count;
    }
}
