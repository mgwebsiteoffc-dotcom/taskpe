<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShopifyClient;
use App\Support\ShopContext;
use Illuminate\Http\Request;
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

        if ($q === '' && !$id) {
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
            $hint = $this->hintFor($e->getMessage());

            // `message` is the key the SPA reads out of a failed response; `error`
            // is kept for anyone reading the endpoint directly.
            return response()->json(['error' => $hint, 'message' => $hint], 502);
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

        return str_contains($msg, 'access') || str_contains($msg, 'ACCESS')
            ? 'Missing API permission. Re-install the app or check scopes.'
            : 'Search failed — try again.';
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

        $client  = new ShopifyClient($ctx->shop());
        $base    = $this->orderQuery($q, $id);
        $since   = $ctx->shop()->orderSearchSince();
        // An exact id lookup stays unbounded — someone pasted an admin URL and
        // expects that one order back, not a date filter.
        $bounded = $since !== null && $id === null;

        try {
            $data = $client->graphql($gql, ['query' => $bounded ? $base.' created_at:>='.$since : $base]);
        } catch (\RuntimeException $e) {
            // The date bound is a guess from the granted scopes; if the store *can*
            // read everything (approved after our scopes column was last synced),
            // one unbounded retry answers the query instead of erroring.
            if (!$bounded || !$this->isProtectedDataDenial($e->getMessage())) {
                throw $e;
            }

            $bounded = false;
            $data    = $client->graphql($gql, ['query' => $base]);
        }

        if ($bounded) {
            $this->notice = 'Showing orders created since Taskpe was installed ('.$since.').';
        }

        return collect($data['orders']['nodes'] ?? [])->map(fn ($o) => [
            'type'     => 'order',
            'id'       => $this->numId($o['id']),
            'gid'      => $o['id'],
            'title'    => $o['name'],
            'subtitle' => trim(($o['totalPriceSet']['shopMoney']['amount'] ?? '').' '.($o['totalPriceSet']['shopMoney']['currencyCode'] ?? '').' · '.strtolower(str_replace('_', ' ', $o['displayFinancialStatus'] ?? ''))),
            'url'      => $ctx->shop()->adminBaseUrl().'/orders/'.$this->numId($o['id']),
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

        $data = (new ShopifyClient($ctx->shop()))->graphql($gql, ['query' => $this->orderQuery($q, $id)]);

        return collect($data['draftOrders']['nodes'] ?? [])->map(fn ($o) => [
            'type'     => 'draft_order',
            'id'       => $this->numId($o['id']),
            'gid'      => $o['id'],
            'title'    => $o['name'],
            'subtitle' => strtolower($o['status'] ?? '').' · '.($o['totalPriceSet']['shopMoney']['amount'] ?? '').' '.($o['totalPriceSet']['shopMoney']['currencyCode'] ?? ''),
            'url'      => $ctx->shop()->adminBaseUrl().'/draft_orders/'.$this->numId($o['id']),
        ])->values()->all();
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

        $data  = (new ShopifyClient($ctx->shop()))->graphql($gql, ['query' => $this->genericQuery($q, $id)]);

        return collect($data['products']['nodes'] ?? [])->map(fn ($p) => [
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

        $data = (new ShopifyClient($ctx->shop()))->graphql($gql, ['query' => $this->genericQuery($q, $id)]);

        return collect($data['customers']['nodes'] ?? [])->map(fn ($c) => [
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

        $data = (new ShopifyClient($ctx->shop()))->graphql($gql, ['query' => $this->genericQuery($q, $id)]);

        return collect($data['articles']['nodes'] ?? [])->map(fn ($a) => [
            'type'     => 'article',
            'id'       => $this->numId($a['id']),
            'gid'      => $a['id'],
            'title'    => $a['title'],
            'subtitle' => $a['blog']['title'] ?? 'Blog',
            'url'      => $ctx->shop()->adminBaseUrl().'/articles/'.$this->numId($a['id']),
        ])->values()->all();
    }

    // ---------------- query builders ----------------

    /** Shopify search syntax for orders/draft orders. */
    protected function orderQuery(string $q, ?int $id): string
    {
        if ($id) {
            return "id:{$id}";
        }
        // #1001, plain numbers, or text all hit the `name` index nicely.
        $q = '#'.ltrim($q, '#');

        return preg_match('/^#\d+$/', $q) ? "name:{$q}" : 'name:*'.ltrim($q, '#').'*';
    }

    protected function genericQuery(string $q, ?int $id): string
    {
        return $id ? "id:{$id}" : $q;
    }

    protected function numId(string $gid): int
    {
        return (int) basename($gid);
    }
}
