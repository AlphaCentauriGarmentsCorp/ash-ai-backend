<?php

namespace App\Services;

use App\Models\Quotation;
use Illuminate\Database\Eloquent\Collection;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Mail\QuotationPdfMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class QuotationService
{
    public function getAll(): Collection
    {
        return Quotation::with('user')->get();
    }

    public function find(int $id): ?Quotation
    {
        return Quotation::with('user')->find($id);
    }

    public function store(array $data, ?Request $request = null): Quotation
    {
        return DB::transaction(function () use ($data, $request) {
            $data['quotation_id'] = $this->generatePoCode('QUO');
            $data['user_id'] = Auth::id();

            $data['items_json'] = $this->decodeJsonField($data['items_json'] ?? null);
            $data['addons_json'] = $this->decodeJsonField($data['addons_json'] ?? null);
            $data['breakdown_json'] = $this->decodeJsonField($data['breakdown_json'] ?? null);

            $data['print_parts_json'] = $this->handlePrintParts($data['print_parts_json'] ?? null, $request);

            $quotation = Quotation::create($data);

            // Generate PDF after creation
            $pdf = Pdf::loadView('pdf', [
                'quotation' => $quotation
            ]);

            $fileName = $quotation->quotation_id . '.pdf';
            $filePath = "quotations/{$fileName}";

            Storage::disk('public')->put($filePath, $pdf->output());

            $quotation->update([
                'pdf_path' => $filePath
            ]);

            // Send email if client_email exists
            if (!empty($quotation->client_email)) {
                Mail::to($quotation->client_email)->send(new QuotationPdfMail($filePath));
            }

            return $quotation->fresh();
        });
    }

    public function update(array $data, int $id, ?Request $request = null): Quotation
    {
        return DB::transaction(function () use ($id, $data, $request) {
            $quotation = Quotation::findOrFail($id);

            if (array_key_exists('items_json', $data)) {
                $data['items_json'] = $this->decodeJsonField($data['items_json']);
            }

            if (array_key_exists('addons_json', $data)) {
                $data['addons_json'] = $this->decodeJsonField($data['addons_json']);
            }

            if (array_key_exists('breakdown_json', $data)) {
                $data['breakdown_json'] = $this->decodeJsonField($data['breakdown_json']);
            }

            if (array_key_exists('print_parts_json', $data)) {
                $data['print_parts_json'] = $this->handlePrintParts($data['print_parts_json'], $request, $quotation);
            }

            $quotation->update($data);

            // Regenerate PDF after update
            $pdf = Pdf::loadView('pdf', [
                'quotation' => $quotation->fresh()
            ]);

            $fileName = $quotation->quotation_id . '.pdf';
            $filePath = "quotations/{$fileName}";

            //Overwrite existing PDF
            Storage::disk('public')->put($filePath, $pdf->output());

            if (!empty($quotation->client_email)) {
                Mail::to($quotation->client_email)->send(new QuotationPdfMail($filePath));
            }

            return $quotation->fresh();
        });
    }

    protected function decodeJsonField($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            return json_decode($value, true);
        }

        return null;
    }

    protected function handlePrintParts($printParts, ?Request $request = null, ?Quotation $quotation = null): ?array
    {
        $decodedParts = $this->decodeJsonField($printParts);

        if (!$decodedParts || !is_array($decodedParts)) {
            return null;
        }

        $result = [];

        foreach ($decodedParts as $index => $partData) {
            $imagePath = $partData['existing_image'] ?? null;

            if ($request && $request->hasFile("print_parts_json.$index.image")) {
                $imagePath = $request->file("print_parts_json.$index.image")
                    ->store('quotation-print-parts', 'public');
            }

            $result[] = [
                'part' => $partData['part'] ?? null,
                'color_count' => isset($partData['color_count']) ? (int) $partData['color_count'] : null,
                'image' => $imagePath,
            ];
        }

        return $result;
    }

    protected function generatePoCode(string $prefix = 'QUO'): string
    {
        $year = now()->year;
        $lastNumber = Quotation::whereYear('created_at', $year)
            ->lockForUpdate()
            ->selectRaw("CAST(SUBSTRING_INDEX(quotation_id, '-', -1) AS UNSIGNED) as num")
            ->orderByDesc('num')
            ->value('num');

        $nextNumber = ($lastNumber ?? 0) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }

    public function delete(int $id): bool
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return false;
        }

        return $quotation->delete();
    }
}