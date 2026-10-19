<?php

namespace App\Console\Commands;

use App\Exceptions\RefundNotAllowed;
use App\Models\Order;
use App\Models\RefundAttempt;
use App\Models\RefundEvent;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the refund log the owner kept before the refund record existed
 * (docs: section 4 of the refund proposal) as manual refund entries, so no
 * refund made before launch is missing from the history. Safe to run again:
 * a line that was imported is skipped. Use --dry-run first.
 */
class ImportRefundLog extends Command
{
    protected $signature = 'refunds:import-log
        {file : CSV or tab-separated export of the refund log spreadsheet}
        {--dry-run : Show what would be imported and change nothing}
        {--user= : Staff user id to record as the actor (default: the first Owner)}';

    protected $description = 'Import the pre-launch manual refund log as manual refund entries.';

    private const COLUMNS = [
        'line' => 'line',
        'order number' => 'order',
        'amount (usd)' => 'amount',
        'method (zelle/paypal)' => 'method',
        'reference number' => 'reference',
        'date sent' => 'sent',
        'corrects line' => 'corrects',
        'stripe failure reason' => 'reason',
        'sent by' => 'sent_by',
    ];

    public function handle(RefundService $refunds): int
    {
        $rows = $this->readRows((string) $this->argument('file'));
        if ($rows === null) {
            return self::FAILURE;
        }

        $actor = $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Owner'))->orderBy('id')->first();

        if (! $actor) {
            $this->error('No staff user to record the import as. Pass --user=ID or create an Owner account.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $imported = $skipped = $failed = 0;

        // A dry run does everything inside a transaction and rolls it back.
        DB::beginTransaction();

        foreach ($rows as $row) {
            try {
                $outcome = $this->importRow($refunds, $actor, $row);
            } catch (RefundNotAllowed $e) {
                $outcome = ['failed', "line {$row['line']}: {$e->getMessage()}"];
            } catch (Throwable $e) {
                $outcome = ['failed', "line {$row['line']}: {$e->getMessage()}"];
            }

            [$status, $message] = $outcome;
            match ($status) {
                'imported' => $imported++,
                'skipped' => $skipped++,
                default => $failed++,
            };
            $status === 'failed' ? $this->error($message) : $this->line($message);
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->info(($dryRun ? '[dry run] ' : '')."Imported {$imported}, skipped {$skipped}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string}
     */
    private function importRow(RefundService $refunds, User $actor, array $row): array
    {
        $line = (int) $row['line'];

        if (RefundEvent::query()->where('event', 'imported_from_log')->where('details->log_line', $line)->exists()
            || RefundEvent::query()->where('event', 'voided')->where('details->log_line', $line)->exists()) {
            return ['skipped', "line {$line}: already imported"];
        }

        $notes = [];

        // A correcting line voids the entry it corrects, then may carry the right values.
        if ($row['corrects'] !== '') {
            $original = RefundEvent::query()->where('event', 'imported_from_log')
                ->where('details->log_line', (int) $row['corrects'])->first();
            $attempt = $original?->attempt;

            if (! $attempt) {
                return ['failed', "line {$line}: line {$row['corrects']} was not imported, so it cannot be corrected"];
            }

            if ($attempt->status === RefundAttempt::RECORDED) {
                $refunds->voidManual(Order::query()->findOrFail($attempt->order_id), $attempt, $actor, "Corrected by refund log line {$line}");
                $notes[] = "voided line {$row['corrects']}";
            }

            if ($row['amount'] === '' && $row['method'] === '') {
                return ['imported', "line {$line}: ".implode(', ', $notes)];
            }
        }

        $method = strtolower($row['method']);
        $amount = str_replace(['$', ','], '', $row['amount']);

        if ($row['order'] === '' || ! is_numeric($amount) || (float) $amount <= 0 || ! in_array($method, ['zelle', 'paypal'], true)
            || $row['reference'] === '' || $row['sent'] === '') {
            return ['failed', "line {$line}: order number, amount, method (Zelle/PayPal), reference and date sent are all required"];
        }

        $order = Order::query()->where('order_number', $row['order'])->first();
        if (! $order) {
            return ['failed', "line {$line}: order {$row['order']} does not exist"];
        }

        $refunds->recordManual(
            $order, $actor, (int) round(((float) $amount) * 100), $method, $row['reference'],
            Carbon::parse($row['sent']), "Imported from the refund log, line {$line}.",
            'Imported from the refund log.', fromLog: true, logLine: $line,
        );
        $notes[] = "recorded \${$amount} by {$method} for {$row['order']}";

        return ['imported', "line {$line}: ".implode(', ', $notes)];
    }

    /** @return list<array<string, string>>|null */
    private function readRows(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return null;
        }

        $handle = fopen($path, 'rb');
        $first = (string) fgets($handle);
        rewind($handle);
        // The spreadsheet header is pasted tab-separated; an export is comma-separated.
        $delimiter = substr_count($first, "\t") >= substr_count($first, ',') ? "\t" : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if (! $header) {
            $this->error('The file is empty.');

            return null;
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $map = [];
        foreach ($header as $index => $name) {
            $key = self::COLUMNS[strtolower(trim((string) $name))] ?? null;
            if ($key) {
                $map[$index] = $key;
            }
        }

        foreach (['line', 'order', 'amount', 'method', 'reference', 'sent', 'corrects'] as $needed) {
            if (! in_array($needed, $map, true)) {
                $this->error("The header row has no column for \"{$needed}\".");

                return null;
            }
        }

        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = array_fill_keys(array_values(self::COLUMNS), '');
            foreach ($map as $index => $key) {
                $row[$key] = trim((string) ($cells[$index] ?? ''));
            }
            if ($row['line'] !== '' && ctype_digit($row['line'])) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        usort($rows, fn (array $a, array $b) => (int) $a['line'] <=> (int) $b['line']);

        return $rows;
    }
}
