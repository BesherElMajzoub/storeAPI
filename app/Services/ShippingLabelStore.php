<?php

namespace App\Services;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Keeps a copy of each purchased label as a PDF on our private disk, so the
 * admin can print it from a phone (Labelife needs a PDF) even after the
 * EasyPost link expires, and hands out short-lived signed links to it.
 */
class ShippingLabelStore
{
    private const DISK = 'local';

    private const LINK_TTL_MINUTES = 15;

    public function __construct(private readonly EasyPostServiceInterface $easyPost) {}

    /**
     * Download the label PDF and record where it is stored. Returns the path,
     * or null when it could not be fetched (the download link retries later).
     */
    public function store(Order $order, ?object $postageLabel = null): ?string
    {
        try {
            if (config('services.easypost.driver') === 'fake') {
                $pdf = $this->placeholderPdf("MOCK LABEL {$order->order_number} {$order->tracking_number}");
            } else {
                $url = $this->pdfUrlFrom($postageLabel);
                if ($url === null) {
                    if (! $order->easypost_shipment_id) {
                        return null;
                    }
                    $url = $this->easyPost->pdfLabelUrl($order->easypost_shipment_id);
                }

                $pdf = Http::timeout(20)->get($url)->throw()->body();
            }

            if (! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('The downloaded label is not a PDF.');
            }

            $path = "labels/{$order->order_number}.pdf";
            Storage::disk(self::DISK)->put($path, $pdf);
            $order->forceFill(['label_path' => $path])->saveQuietly();

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Shipping label PDF could not be stored.', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Path of the stored PDF, fetching it first when it is missing.
     */
    public function path(Order $order): ?string
    {
        if ($order->label_path && Storage::disk(self::DISK)->exists($order->label_path)) {
            return $order->label_path;
        }

        return $order->tracking_number ? $this->store($order) : null;
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /**
     * A link that opens the label without a bearer token (a phone tapping a
     * link cannot send one), valid for 15 minutes.
     */
    public function downloadUrl(Order $order): ?string
    {
        if (! $order->tracking_number || (! $order->label_path && ! $order->easypost_shipment_id)) {
            return null;
        }

        return URL::temporarySignedRoute('admin.shipments.label', now()->addMinutes(self::LINK_TTL_MINUTES), ['order' => $order->id]);
    }

    /**
     * A one-line 4x6 (288x432 pt) PDF, so the fake shipping driver can be
     * demoed end to end without EasyPost.
     */
    private function placeholderPdf(string $text): string
    {
        $text = preg_replace('/[^A-Za-z0-9 \-]/', '', $text);
        $stream = "BT /F1 14 Tf 20 400 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function pdfUrlFrom(?object $postageLabel): ?string
    {
        if ($postageLabel === null) {
            return null;
        }

        if (! empty($postageLabel->label_pdf_url)) {
            return $postageLabel->label_pdf_url;
        }

        $isPdf = ($postageLabel->label_file_type ?? null) === 'application/pdf'
            || str_ends_with(strtolower(parse_url((string) ($postageLabel->label_url ?? ''), PHP_URL_PATH) ?: ''), '.pdf');

        return $isPdf ? $postageLabel->label_url : null;
    }
}
