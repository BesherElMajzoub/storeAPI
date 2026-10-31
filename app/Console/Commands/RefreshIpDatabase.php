<?php

namespace App\Console\Commands;

use App\Services\Address\IpLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

class RefreshIpDatabase extends Command
{
    protected $signature = 'geo:refresh-ip-database';

    protected $description = 'Download the free DB-IP "IP to City Lite" file used to favour address suggestions near the visitor (CC BY 4.0, credit DB-IP)';

    public function handle(): int
    {
        $target = IpLocator::databasePath();
        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // The file for the current month appears a few days into the month; fall back to the one before.
        foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
            $url = str_replace('{month}', $month, config('services.geo.ip_source_url'));
            $gz = $dir.'/download-'.uniqid().'.mmdb.gz';
            $tmp = $dir.'/download-'.uniqid().'.mmdb';

            try {
                $response = Http::timeout(600)->withOptions(['sink' => $gz])->get($url);
                if ($response->failed()) {
                    $this->warn("{$month}: not available (HTTP {$response->status()}).");

                    continue;
                }

                $this->gunzip($gz, $tmp);
                // Only replace the current file with one that opens and answers a lookup.
                (new Reader($tmp))->close();
                rename($tmp, $target);
                $this->info("IP database updated from {$month}.");

                return self::SUCCESS;
            } catch (\Throwable $e) {
                Log::warning('geo:refresh-ip-database failed', ['month' => $month, 'error' => $e->getMessage()]);
                $this->warn("{$month}: {$e->getMessage()}");
            } finally {
                @unlink($gz);
                @unlink($tmp);
            }
        }

        $this->error('Could not update the IP database; the existing file (if any) was kept.');

        return self::FAILURE;
    }

    private function gunzip(string $from, string $to): void
    {
        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');
        if (! $in || ! $out) {
            throw new \RuntimeException('Cannot open files to unpack the download.');
        }
        while (! gzeof($in)) {
            fwrite($out, gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);
    }
}
