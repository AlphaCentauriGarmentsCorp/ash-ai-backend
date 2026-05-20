<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuotationService;
use App\Http\Requests\Quotation\Store;
use App\Http\Requests\Quotation\Update;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class QuotationController extends Controller
{
    protected $service;

    public function __construct(QuotationService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printTypes = $this->service->getAll();
        return QuotationResource::collection($printTypes);
    }
public function store(Store $request)
{
    $validated = $request->validated();

    // Process uploaded print part files. Use $request->file() (NOT input)
    // so we get UploadedFile instances regardless of FormData nesting.
    // Handles both flat shape (print_parts_files[0] = file, matches the
    // current validator) AND legacy nested shape (print_parts_files[0][0] = file).
    $files = $this->collectUploadedPrintParts($request);
    if (! empty($files)) {
        $validated['print_parts_files'] = json_encode($files);
    }

    // Create the quotation using the service layer
    $quotation = $this->service->store($validated, $request);

    // Return the response with the newly created quotation
    return (new QuotationResource($quotation))->response()->setStatusCode(201);
}

    public function update(Update $request, $id)
    {
        $validated = $request->validated();

        $files = $this->collectUploadedPrintParts($request);
        if (! empty($files)) {
            $validated['print_parts_files'] = json_encode($files);
        }

        // Update the quotation with new data
        $quotation = $this->service->update($validated, $id, $request);

        return new QuotationResource($quotation);
    }

    /**
     * Walk the uploaded `print_parts_files` payload and return an array of
     * stored file paths, regardless of whether the frontend sent the files
     * flat (`print_parts_files[0] = file`) or nested
     * (`print_parts_files[0][0] = file`). This way the controller stays
     * robust against either FormData shape.
     */
    protected function collectUploadedPrintParts(\Illuminate\Http\Request $request): array
    {
        $raw = $request->file('print_parts_files');
        if (empty($raw) || ! is_array($raw)) {
            return [];
        }

        $stored = [];

        foreach ($raw as $item) {
            // Flat shape: $item is an UploadedFile.
            if ($item instanceof \Illuminate\Http\UploadedFile) {
                if ($item->isValid()) {
                    $stored[] = $item->store('quotation-print-parts', 'public');
                }
                continue;
            }

            // Nested shape: $item is itself an array of UploadedFile objects.
            if (is_array($item)) {
                foreach ($item as $subItem) {
                    if ($subItem instanceof \Illuminate\Http\UploadedFile && $subItem->isValid()) {
                        $stored[] = $subItem->store('quotation-print-parts', 'public');
                    }
                }
            }
        }

        return $stored;
    }


    public function show($id)
    {
        $quotation = $this->service->find($id);
        if (!$quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }
        return new QuotationResource($quotation);
    }

    public function generatePDF($id)
    {
        $quotation = Quotation::findOrFail($id);

        $fileName = $quotation->quotation_id . '.pdf';
        $filePath = "quotations/{$fileName}";

        if (!Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        // Get the full path in storage
        $fullPath = Storage::disk('public')->path($filePath);

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"$fileName\"",
        ]);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        return response()->json(['message' => 'Quotation deleted successfully']);
    }

    /**
     * Mark a quotation as Converted and return the prefill payload that
     * the frontend can use to populate /orders/new.
     *
     * Returns 409 if the quotation is already Converted.
     */
    public function confirm($id)
    {
        $result = $this->service->confirmAndConvert((int) $id);

        return response()->json($result);
    }
}
