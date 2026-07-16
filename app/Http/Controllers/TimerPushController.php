<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TimerPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimerPushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seconds' => ['required', 'integer', 'min:1', 'max:7200'],
            'pool_id' => ['nullable', 'integer'],
            'fase' => ['required', 'string', 'in:lavagem,enxaguamento'],
        ]);

        TimerPush::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'pool_id' => $data['pool_id'] ?? null,
                'fase' => $data['fase'],
                'sent_at' => null,
                'cancelled_at' => null,
            ],
            [
                'fire_at' => Carbon::now()->addSeconds($data['seconds']),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pool_id' => ['nullable', 'integer'],
            'fase' => ['nullable', 'string', 'in:lavagem,enxaguamento'],
        ]);

        $query = TimerPush::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at');

        if ($request->has('fase')) {
            $query->where('fase', $data['fase'])
                ->where('pool_id', $data['pool_id'] ?? null);
        }

        $query->update(['cancelled_at' => Carbon::now()]);

        return response()->json(['ok' => true]);
    }
}
