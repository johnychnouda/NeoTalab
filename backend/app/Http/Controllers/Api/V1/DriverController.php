<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request, DriverService $drivers): JsonResponse
    {
        return response()->json(['drivers' => $drivers->list($request->user()->merchant)]);
    }

    public function store(Request $request, DriverService $drivers): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsappNumber' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $driver = $drivers->create($request->user()->merchant, $data);

        return response()->json(['driver' => $driver], 201);
    }

    public function setStatus(Request $request, DriverService $drivers, string $driver): JsonResponse
    {
        $request->validate(['status' => ['required', 'string']]);
        $row = $drivers->setStatus($request->user()->merchant, $driver, $request->input('status'));

        return response()->json(['driver' => $row]);
    }

    public function destroy(Request $request, DriverService $drivers, string $driver): JsonResponse
    {
        $drivers->deactivate($request->user()->merchant, $driver);

        return response()->json(['message' => 'Driver deactivated.']);
    }
}
