<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoardColumn;
use App\Support\ShopContext;
use Illuminate\Http\Request;

class ColumnController extends Controller
{
    /** POST /api/columns */
    public function store(Request $request, ShopContext $ctx)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:60'],
            'is_done_stage' => ['boolean'],
            'team'          => ['nullable', 'string', 'max:40'],
        ]);

        abort_if($ctx->shop()->columns()->count() >= 8, 422, 'Maximum 8 columns');

        $col = $ctx->shop()->columns()->create([
            'name'          => $data['name'],
            'is_done_stage' => $data['is_done_stage'] ?? false,
            'team'          => $this->team($data['team'] ?? null),
            'position'      => (int) $ctx->shop()->columns()->max('position') + 1,
        ]);

        return response()->json(['id' => $col->id], 201);
    }

    /** PATCH /api/columns/{id} — rename / toggle done-stage / reorder. */
    public function update(Request $request, ShopContext $ctx, int $id)
    {
        $col = $this->findColumn($ctx, $id);

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:60'],
            'is_done_stage' => ['sometimes', 'boolean'],
            'position'      => ['sometimes', 'integer', 'min:0'],
            'team'          => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        if (array_key_exists('team', $data)) {
            $data['team'] = $this->team($data['team']);
        }

        $col->update($data);

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/columns/{id} — its tasks slide into the first remaining column. */
    public function destroy(ShopContext $ctx, int $id)
    {
        $col = $this->findColumn($ctx, $id);

        $remaining = $ctx->shop()->columns()->where('id', '!=', $col->id)->orderBy('position')->first();
        abort_if(!$remaining, 422, 'Cannot delete the last column');

        $ctx->shop()->tasks()->where('column_id', $col->id)->update(['column_id' => $remaining->id]);
        $col->delete();

        return response()->json(['ok' => true, 'moved_to' => $remaining->id]);
    }

    /** Empty string from the UI means "no team"; store it as NULL, not ''. */
    protected function team(?string $team): ?string
    {
        $team = trim((string) $team);

        return $team === '' ? null : $team;
    }

    protected function findColumn(ShopContext $ctx, int $id): BoardColumn
    {
        return $ctx->shop()->columns()->where('id', $id)->firstOrFail();
    }
}
