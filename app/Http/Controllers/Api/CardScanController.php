<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class CardScanController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'uid'       => ['required', 'string', 'max:64'],
            'reader_id' => ['nullable', 'string', 'max:64'],
            'scanned_at' => ['nullable', 'string'],
        ]);

        Log::info('RFID Scan', $data);

        // Taruh logic kamu di sini (lookup kartu, checkin, dsb)
        return response()->json([
            'ok'         => true,
            'uid'        => $data['uid'],
            'reader_id'  => $data['reader_id'] ?? null,
            'received_at' => now()->toISOString(),
        ]);
    }

    public function latest()
    {
        return response()->json(['ok' => true, 'uid' => null]);
    }
}
