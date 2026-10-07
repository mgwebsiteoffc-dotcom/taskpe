<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopifyClient;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Lets a task link to any Shopify object. Queries run server-side through
 * the Admin GraphQL API (the storefront never sees the offline token).
 *
 * PCD note: we deliberately select ONLY non-PII fields — order number,
 * financial status, product title, customer *display name* (the single PCD
 * field justified in the listing), article title. No addresses/emails/phones.
 */
class ResourceSearchController extends Controller
{
    /**
     * Set by a per-type query when the result set is deliberately narrower than it
     * looks, so the UI can explain the gap instead of the merchant concluding that
     * the order does not exist.
     */
    private ?string $notice = null;

    /** GET /api/resources/search?type=order&q=1001  (or &id=5123456789 for exact) */
    public function search(Request $request, ShopContext $ctx)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['order', 'draft_order', 'product', 'customer', 'article'])],
            'q'    => ['nullable', 'string', 'max:80'],
            'id'   => ['nullable', 'integer'],
        ]);

        $q  = trim((string) ($data['q'] ?? ''));
        $id = $data['id'] ?? null;
        $this->notice = null;

        if (trim((string) $q) === '' && !$id) {
            return response()->json(['items' => []]);
        }

        try {
            $items = match ($data['type']) {
                'order'       => $this->searchOrders($ctx, $q, $id),
                'draft_order' => $this->searchDraftOrders($ctx, $q, $id),
                'product'     => $this->searchProducts($ctx, $q, $id),
                'customer'    => $this->searchCustomers($ctx, $q, $id),
                'article'     => $this->searchArticles($ctx, $q, $id),
            };
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            // The merchant gets a sentence they can act on; the raw Shopify reply
            // goes to the log, and into `detail` only while debugging. Both belong
            // somewhere, and not in the same place.
            // An internal PHP error is a bug to fix, so it is logged as one. The
            // search string goes in too: "type=order q=101" is enough to reproduce it.
            Log::log(preg_match('/must be of type|TypeError|undefined method|Call to /', $msg) ? 'error' : 'warning',
                'Resource search failed', [
                'shop'  => $ctx->shop()->domain,
                'type'  => $data['type'],
                'q'     => mb_substr($q, 0, 80),
                'error' => mb_substr($msg, 0, 400),
            ]);

            return response()->json([
                'error'   => 'search_failed',
                'message' => $this->hintFor($msg),
                'detail'  => config('app.debug') ? mb_substr($msg, 0, 600) : null,
            ], 502);
        }

        return $this->notice === null
            ? response()->json(['items' => $items])
            : response()->json(['items' => $items, 'note' => $this->notice]);
    }

    /**
     * A protected-customer-data refusal is not a broken token and re-installing
     * will not fix it — saying "missing permission" there sends the merchant off
     * hunting the wrong switch, so name the actual one.
     */
    protected function isProtectedDataDenial(string $message): bool
    {
        return str_contains($message, 'ACCESS_DENIED')
            || str_contains($message, 'protected customer data')
            || str_contains($message, 'Order object');
    }

    protected function hintFor(string $msg): string
    {
        if ($this->isProtectedDataDenial($msg)) {
            return "Shopify has not approved this app for the store's order data yet, so orders cannot be"
                .' searched. In the Partner Dashboard → your app → API access → Protected customer'
                ." data, request the `read_all_orders` scope, then re-install the app. Until that approval"
                .' lands, only orders created after the install are readable.';
        }

        // Busy or unreachable is the one case where "try again" is honest advice.
        if (preg_match('/timed out|timeout|cURL error|unavailable|Connection|network|HTTP 42|HTTP 5\d\d|Throttl/i', $msg)) {
            return 'Shopify is busy or slow right now. This usually works a second later.';
        }

        if (preg_match('/USER_ERROR|Invalid query|syntax|parse|wildcard/i', $msg)) {
            return 'Shopify could not read that search. Try just the order number, for example 1042.';
        }

        if (str_contains($msg, 'access') || str_contains($msg, 'ACCESS')) {
            return 'Missing API permission. Re-install the app or check scopes.';
        }

        // A TypeError or ArgumentCountError here is our bug, not Shopify's and not the
        // merchant's. Saying "try again" over it sends everyone to the wrong door, so the
        // sentence points at the log line that has the truth.
        if (preg_match('/must be of type|Argument #\d|TypeError|\bCall to (undefined|static)|undefined method|too few arguments/i', $msg)) {
            return 'TaskPe hit an error in this search — nothing you did wrong. '
                . 'The store log has the line (search for \'Resource search failed\').';
        }

        return 'Shopify could not answer this search — try again in a moment.';
    }

    // ---------------- per-type queries ----------------

    protected function searchOrders(ShopContext $ctx, string $q, ?int $id): array
    {
        $gql = <<<'GQL'
        query ($query: String!) {
          orders(first: 10, query: $query) {
            nodes { id name displayFinancialStatus processedAt totalPriceSet { shopMoney { amount currencyCode } } }
          }
        }
        GQL;

        $queries = $this->orderQueries($q, $id);

        if (!$queries) {
            return $this->tooNarrow();
        }

        // An exact id lookup stays unbounded — someone pasted an admin URL and expects
        // that one order back, not a date filter.
        $since   = $id === null ? $ctx->shop()->orderSearchSince() : null;
        [$nodes, $bounded] = $this->run($ctx, $gql, 'orders', $queries, $since);

        if ($bounded) {
            $this->notice = 'Shopify only lets this app read orders created since TaskPe was installed ('
                . $since . '), so anything older than that cannot appear here.';
        }

        return collect($nodes)->map(fn ($o) => [
            'type'     => 'order',
            'id'       => $this->numId($o['id']),
            'gid'      => $o['id'],
            'title'    => $o['name'],
            'subtitle' => trim(($o['totalPriceSet']['shopMoney']['amount'] ?? '') . ' ' . ($o['totalPriceSet']['shopMoney']['currencyCode'] ?? '') . ' · ' . strtolower(str_replace('_', ' ', $o['displayFinancialStatus'] ?? ''))),
            'url'      => $ctx->shop()->adminBaseUrl() . '/orders/' . $this->numId($o['id']),
        ])->values()->all();
    }

    protected function searchDraftOrders(ShopContext $ctx, string $q, ?int $id): array
    {
        $gql = <<<'GQL'
        query ($query: String!) {
          draftOrders(first: 10, query: $query) {
            nodes { id name status createdAt totalPriceSet { shopMoney { amount currencyCode } } }
          }
        }
        GQL;

        $queries = $this->draftOrderQueries($q, $id);

        if (!$queries) {
            return $this->tooNarrow();
        }

        // Draft orders are not protected customer data, so no install-date bound.
        [$nodes, ] = $this->run($ctx, $gql, 'draftOrders', $queries, null);

        return collect($nodes)->map(fn ($o) => [
            'type'     => 'draft_order',
            'id'       => $this->numId($o['id']),
            'gid'      => $o['id'],
            'title'    => $o['name'],
            'subtitle' => strtolower($o['status'] ?? '') . ' · ' . ($o['totalPriceSet']['shopMoney']['amount'] ?? '') . ' ' . ($o['totalPriceSet']['shopMoney']['currencyCode'] ?? ''),
            'url'      => $ctx->shop()->adminBaseUrl() . '/draft_orders/' . $this->numId($o['id']),
        ])->values()->all();
    }

    /**
     * Runs the candidate queries narrowest-first and stops at the first that answers.
     *
     * @return array{0: array, 1: bool}  the nodes, and whether a date bound is in play
     */
    protected function run(ShopContext $ctx, string $gql, string $field, array $queries, ?string $since): array
    {
        $client  = new ShopifyClient($ctx->shop());
        $bounded = $since !== null;
        $nodes   = [];

        foreach ($queries as $query) {
            // Quoted because that is the form our search-syntax reference writes dates
            // in, and a bare `2026-10-07` is one parser change away from a syntax error
            // — which used to surface to the merchant as "Search failed — try again.".
            $withBound = $bounded ? $query . " created_at:>='" . $since . "'" : $query;

            try {
                $data = $client->graphql($gql, ['query' => $withBound]);
            } catch (\RuntimeException $e) {
                // The bound is only a guess from the scopes we last saw for this store. If
                // it can actually read everything (approved after `shops.scopes` was last
                // synced), one unbounded retry answers the search instead of erroring.
                if (!$bounded || !$this->isProtectedDataDenial($e->getMessage())) {
                    throw $e;
                }

                $bounded = false;
                $data    = $client->graphql($gql, ['query' => $query]);
            }

            $nodes = $data[$field]['nodes'] ?? [];

            if ($nodes) {
                break;      // the narrow reading is the right one; only a miss widens it
            }
        }

        return [$nodes, $bounded];
    }

    /** Input that leaves nothing to search for — said out loud rather than queried. */
    protected function tooNarrow(): array
    {
        $this->notice = 'Type an order number, for example 1042, or part of a name.';

        return [];
    }

    protected function searchProducts(ShopContext $ctx, string $q, ?int $id): array
    {
        $gql = <<<'GQL'
        query ($query: String!) {
          products(first: 10, query: $query) {
            nodes { id title status featuredMedia { preview { image { url } } } }
          }
        }
        GQL;

        $queries = $this->genericQueries($q, $id);

        if (!$queries) {
            return $this->tooNarrow();
        }

        [$nodes, ] = $this->run($ctx, $gql, 'products', $queries, null);

        return collect($nodes)->map(fn ($p) => [
            'type'     => 'product',
            'id'       => $this->numId($p['id']),
            'gid'      => $p['id'],
            'title'    => $p['title'],
            'subtitle' => strtolower($p['status'] ?? ''),
            'image'    => $p['featuredMedia']['preview']['image']['url'] ?? null,
            'url'      => $ctx->shop()->adminBaseUrl().'/products/'.$this->numId($p['id']),
        ])->values()->all();
    }

    protected function searchCustomers(ShopContext $ctx, string $q, ?int $id): array
    {
        // displayName is the ONLY PCD field we touch (declared in listing).
        $gql = <<<'GQL'
        query ($query: String!) {
          customers(first: 10, query: $query) {
            nodes { id displayName numberOfOrders }
          }
        }
        GQL;

        $queries = $this->genericQueries($q, $id);

        if (!$queries) {
            return $this->tooNarrow();
        }

        [$nodes, ] = $this->run($ctx, $gql, 'customers', $queries, null);

        return collect($nodes)->map(fn ($c) => [
            'type'     => 'customer',
            'id'       => $this->numId($c['id']),
            'gid'      => $c['id'],
            'title'    => $c['displayName'],
            'subtitle' => ($c['numberOfOrders'] ?? '0').' orders',
            'url'      => $ctx->shop()->adminBaseUrl().'/customers/'.$this->numId($c['id']),
        ])->values()->all();
    }

    protected function searchArticles(ShopContext $ctx, string $q, ?int $id): array
    {
        $gql = <<<'GQL'
        query ($query: String!) {
          articles(first: 10, query: $query) {
            nodes { id title blog { title } }
          }
        }
        GQL;

        $queries = $this->genericQueries($q, $id);

        if (!$queries) {
            return $this->tooNarrow();
        }

        [$nodes, ] = $this->run($ctx, $gql, 'articles', $queries, null);

        return collect($nodes)->map(fn ($a) => [
            'type'     => 'article',
            'id'       => $this->numId($a['id']),
            'gid'      => $a['id'],
            'title'    => $a['title'],
            'subtitle' => $a['blog']['title'] ?? 'Blog',
            'url'      => $ctx->shop()->adminBaseUrl().'/articles/'.$this->numId($a['id']),
        ])->values()->all();
    }

    // ---------------- query builders ----------------
    //
    // Shopify's search grammar is small and unforgiving, and the shape built here
    // used to break in the three ways a merchant hits every day:
    //
    //   * `name:*Ravi*` — a LEADING wildcard. Shopify only supports a *trailing* one
    //     (a "prefix query"), so any order search that was not a number was a syntax
    //     error, and the picker showed "Search failed — try again." for it.
    //   * unquoted values — `#`, quotes, colons and parentheses are syntax, so an
    //     order called `TEST#1042` or a customer called `O'Brien` could not be found.
    //   * punctuation-only input (`#`, `?`) produced an empty term and a doomed query.
    //
    // Each builder therefore returns a short LIST of queries to try in order: a store
    // whose order names are not `#1042` still gets its order on the second attempt, and
    // the second attempt only happens when the first found nothing.

    /** @return string[] */
    protected function orderQueries(string $q, ?int $id): array
    {
        if ($id) {
            return ["id:{$id}"];
        }

        $term = $this->term($q);

        if ($term === '') {
            return [];
        }

        if (preg_match('/^\d{1,8}$/', $term)) {
            // An order number. Quoted, because `#` is not a character Shopify's parser
            // takes bare, and the name is stored with the hash on it.
            return ["name:'#{$term}'", "'{$term}'"];
        }

        if (preg_match('/^\d{9,}$/', $term)) {
            return ["id:{$term}"];        // a pasted numeric id, not a shop order number
        }

        return $this->textQueries($term);
    }

    /** Draft orders have no `name:` filter, so the number goes through full text. */
    protected function draftOrderQueries(string $q, ?int $id): array
    {
        if ($id) {
            return ["id:{$id}"];
        }

        $term = $this->term($q);

        if ($term === '') {
            return [];
        }

        return preg_match('/^\d{1,8}$/', $term)
            ? ["'#{$term}'", "'{$term}'"]
            : $this->textQueries($term);
    }

    /** Products / customers / articles: the default search is the widest thing that works. */
    protected function genericQueries(string $q, ?int $id): array
    {
        if ($id) {
            return ["id:{$id}"];
        }

        $term = $this->term($q);

        return $term === '' ? [] : $this->textQueries($term);
    }

    /** @return string[] */
    protected function textQueries(string $term): array
    {
        $bare = preg_match('/^[\p{L}\p{N}._,\-\/]+$/u', $term) === 1;

        // Prefix first, so "hood" finds "hoodie"; then the phrase as typed.
        return $bare && !preg_match('/\s/', $term)
            ? ["{$term}*", "'{$term}'"]
            : ["'{$term}'"];
    }

    /**
     * Everything Shopify reads as syntax rather than as what the person typed is
     * dropped — quotes, parens, field separators, booleans, wildcards. What is left
     * is either one bare word or a phrase we then quote, so a pasted
     * `title:"x" AND tag:y` cannot smuggle a filter the merchant never asked for.
     */
    protected function term(string $raw): string
    {
        $t = str_replace(["\r", "\n", "\t"], ' ', $raw);
        $t = preg_replace('/[^\p{L}\p{N} .,\-\/]+/u', ' ', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) $t), " .,-/");

        return mb_substr($t, 0, 60);
    }

    protected function numId(string $gid): int
    {
        return (int) basename($gid);
    }
}
