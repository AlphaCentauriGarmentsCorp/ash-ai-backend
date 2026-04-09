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

    public function store(array $data): Quotation
    {
        return DB::transaction(function () use ($data) {

            $data['quotation_id'] = $this->generatePoCode('QUO');
            $data['user_id']  = Auth::id();

            $quotation = Quotation::create($data);

            // Generate PDF
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

            return $quotation;
        });
    }

    public function update(array $data, int $id): Quotation
    {
        return DB::transaction(function () use ($id, $data) {

            $quotation = Quotation::findOrFail($id);

            // Update quotation data
            $quotation->update($data);

            // Regenerate PDF
            $pdf = Pdf::loadView('pdf', [
                'quotation' => $quotation->fresh()
            ]);

            $fileName = $quotation->quotation_id . '.pdf';
            $filePath = "quotations/{$fileName}";

            // Overwrite existing PDF
            Storage::disk('public')->put($filePath, $pdf->output());


            if (!empty($quotation->client_email)) {
                Mail::to($quotation->client_email)->send(new QuotationPdfMail($filePath));
            }

            return $quotation->fresh();
        });
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
