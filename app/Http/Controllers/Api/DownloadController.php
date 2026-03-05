<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    public function download(Request $request)
    {
        $path = $request->query('path');

        if (!$path) {
            return response()->json(['error' => 'File path is required'], 400);
        }

        $relativePath = ltrim(str_replace('/storage/', '', $path), '/');
        if (!Storage::disk('public')->exists($relativePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }
        $fullPath = Storage::disk('public')->path($relativePath);

        return response()->download($fullPath);
    }
}
