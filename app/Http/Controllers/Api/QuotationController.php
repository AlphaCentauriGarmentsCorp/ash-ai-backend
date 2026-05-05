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

    // Ensure that print_parts_files is included and processed
    if ($request->has('print_parts_files')) {
        $files = [];
        foreach ($request->input('print_parts_files') as $filesArray) {
            foreach ($filesArray as $file) {
                if ($file && $file->isValid()) {
                    $filePath = $file->store('quotation-print-parts', 'public'); // Store file in 'quotation-print-parts' folder
                    $files[] = $filePath; // Add the file path to the array
                }
            }
        }
        // Add the file paths as JSON to the validated data
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

        if ($request->has('print_parts_files')) {
            $files = [];
            foreach ($request->input('print_parts_files') as $filesArray) {
                foreach ($filesArray as $file) {
                    if ($file && $file->isValid()) {
                        $filePath = $file->store('quotation-print-parts', 'public');
                        $files[] = $filePath;
                }
            }
        }
        
            $validated['print_parts_files'] = json_encode($files);

        }

        // Update the quotation with new data
        $quotation = $this->service->update($validated, $id, $request);

        return new QuotationResource($quotation);

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
}
