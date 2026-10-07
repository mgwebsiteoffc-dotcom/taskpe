<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ShopContext;
use Illuminate\Http\Request;

/**
 * Teams are the department names a shop hangs columns on — Accounting, Warehouse,
 * Fulfilment — and the only way the dashboard can answer "who is carrying what"
 * per department.
 *
 * They live in the shop's JSON settings, not in a table, on purpose: a team is a
 * label with no behaviour of its own, and `columns.team` already owns the link.
 * What the list *does* buy is a team that exists before any column uses it, so
 * the owner can set the vocabulary first ("we will have a Returns desk") instead
 * of discovering that a department only appears once somebody remembers to type
 * the same word into a column.
 *
 * Names are compared case-insensitively and stored trimmed: `warehouse` and
 * `Warehouse` on one board is two rows in every chart, and merchants do both.
 */
class TeamController extends Controller
{
    /** Enough for a 40-person shop, few enough that the dashboard stays readable. */
    private const MAX = 12;

    private const MAX_LEN = 40;

    /** GET /api/teams */
    public function index(ShopContext $ctx)
    {
        return response()->json(['teams' => $this->teams($ctx)]);
    }

    /** POST /api/teams */
    public function store(Request $request, ShopContext $ctx)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:' . self::MAX_LEN]]);
        $name = $this->clean($data['name']);

        if ($name === '') {
            return $this->fail('Give the team a name, for example Accounting.');
        }

        $teams = $this->teams($ctx);

        if ($this->find($teams, $name) !== null) {
            return $this->fail('"' . $name . '" is already one of your teams.');
        }

        if (count($teams) >= self::MAX) {
            return $this->fail('Up to ' . self::MAX . ' teams — reuse a name rather than adding a near-duplicate.');
        }

        $teams[] = $name;
        $this->put($ctx, $this->sorted($teams));

        return response()->json(['ok' => true, 'teams' => $this->sorted($teams)], 201);
    }

    /**
     * PATCH /api/teams/{name} — rename the team and retag every column carrying
     * the old spelling. Both halves have to happen in one call: a rename that
     * leaves three columns on the old name splits one department down the middle
     * of the dashboard, and nobody notices for a week.
     */
    public function update(Request $request, ShopContext $ctx, string $name)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:' . self::MAX_LEN]]);
        $to = $this->clean($data['name']);
        $from = $this->clean(urldecode($name));

        if ($to === '') {
            return $this->fail('Give the team a name.');
        }

        $teams = $this->teams($ctx);
        $at = $this->find($teams, $from);

        if ($at === null) {
            abort(404, 'No team called ' . $from . '.');
        }

        $clash = $this->find($teams, $to);

        if ($clash !== null && $clash !== $at) {
            return $this->fail('"' . $to . '" is already one of your teams.');
        }

        $teams[$at] = $to;
        $this->put($ctx, $this->sorted($teams));

        $ctx->shop()->columns()
            ->whereRaw('LOWER(TRIM(team)) = ?', [mb_strtolower($from)])
            ->update(['team' => $to]);

        return response()->json(['ok' => true, 'teams' => $this->sorted($teams)]);
    }

    /**
     * DELETE /api/teams/{name} — refused while columns still carry the name, since
     * silently untagging a department would make its work vanish from the dashboard.
     */
    public function destroy(ShopContext $ctx, string $name)
    {
        $from = $this->clean(urldecode($name));
        $teams = $this->teams($ctx);
        $at = $this->find($teams, $from);

        if ($at === null) {
            abort(404, 'No team called ' . $from . '.');
        }

        $used = $ctx->shop()->columns()
            ->whereRaw('LOWER(TRIM(team)) = ?', [mb_strtolower($from)])
            ->count();

        if ($used > 0) {
            return $this->fail($used . ' column' . ($used === 1 ? '' : 's') . ' still '
                . ($used === 1 ? 'uses' : 'use') . ' ' . $from
                . '. Edit those columns first and clear their team.');
        }

        array_splice($teams, $at, 1);
        $this->put($ctx, $teams);

        return response()->json(['ok' => true, 'teams' => $teams]);
    }

    /* ------------------------------------------------------------------ glue */

    protected function teams(ShopContext $ctx): array
    {
        $seen = [];

        foreach ((array) $ctx->shop()->setting('teams', []) as $t) {
            $name = $this->clean((string) $t);
            // `warehouse` and `Warehouse` are one department; a settings list written
            // by hand (or by two people at once) can hold both, and every chart would
            // then split down the middle.
            $key = mb_strtolower($name);
            if ($name !== '' && !isset($seen[$key])) {
                $seen[$key] = $name;
            }
        }

        return $this->sorted(array_values($seen));
    }

    protected function put(ShopContext $ctx, array $teams): void
    {
        $ctx->shop()->setSetting('teams', array_values($teams));
        $ctx->shop()->save();
    }

    /** The store is the tenant; nothing here may read another shop's settings. */
    protected function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    protected function find(array $teams, string $name): ?int
    {
        foreach ($teams as $i => $t) {
            if (mb_strtolower($t) === mb_strtolower($name)) {
                return $i;
            }
        }

        return null;
    }

    protected function sorted(array $teams): array
    {
        usort($teams, fn ($a, $b) => strcasecmp($a, $b));

        return array_values($teams);
    }

    /** A 422 with a sentence the app can show as-is — these are user errors, not faults. */
    protected function fail(string $message)
    {
        return response()->json(['error' => 'bad_team', 'message' => $message], 422);
    }
}
