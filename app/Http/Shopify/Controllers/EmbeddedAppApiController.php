<?php

namespace App\Http\Shopify\Controllers;

use App\Domain\Shopify\Api\ShopifyThemeInspector;
use App\Domain\Shopify\Metafields\SyncShopMetafieldsJob;
use App\Http\Shopify\ShopifyEmbeddedContext;
use App\Http\Widget\WidgetResponse;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EmbeddedAppApiController — the embedded shell's data source.
 *
 * Runs behind VerifyShopifySessionToken: the tenant is bound, the owner authenticated.
 * The payload is a WHITELIST — the public site_key ships (it is public by design), the
 * widget_secret and the offline token never assemble into any response.
 */
final class EmbeddedAppApiController
{
    // === CONSTANTS ===
    private const CFG_PANEL_PATH = 'shopify.merchant_panel_path';

    private const CFG_APP_URL = 'app.url';

    private const CFG_EXT_UID = 'shopify.theme_extension.uid';

    private const CFG_EMBED_HANDLE = 'shopify.theme_extension.embed_handle';

    // The theme-editor deep link that activates our app-embed block in the MAIN theme.
    private const THEME_EDITOR_TEMPLATE = 'https://%s/admin/themes/current/editor?context=apps&activateAppId=%s/%s';

    public function __construct(
        private readonly ShopifyThemeInspector $themes,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $context = ShopifyEmbeddedContext::of($request);

        $account = $context->connection->account;
        $site = $context->site;
        $shop = $context->shopDomain();

        // Self-heal: stores installed before the metafield sync existed (or whose sync
        // failed) converge the moment the merchant opens the app. Cheap DB compare here;
        // the unique, convergent job does the (single) API write.
        if ($context->connection->metafields_synced_key !== (string) $site->site_key) {
            SyncShopMetafieldsJob::dispatch((int) $site->account_id, (int) $site->getKey());
        }

        // Self-heal the widget Origin wall too: stores connected BEFORE the installer began
        // allow-listing the shop's own origin get it added on app open (idempotent).
        $site->allowOrigin($shop);
        if ($site->isDirty()) {
            $site->save();
        }

        $panelBase = rtrim((string) config(self::CFG_APP_URL), '/')
            .rtrim((string) config(self::CFG_PANEL_PATH), '/');

        return WidgetResponse::ok([
            'shop' => ['domain' => $shop],
            'account' => [
                'name' => (string) $account->name,
                'locale' => (string) $account->locale,
            ],
            'owner' => ['email' => (string) $context->owner->email],
            'site' => [
                'id' => (int) $site->getKey(),
                'slug' => (string) $site->slug,
                'site_key' => (string) $site->site_key,
            ],
            'connection' => [
                'status' => (string) $context->connection->status,
                'installed_at' => $context->connection->installed_at?->toIso8601String(),
            ],
            'credits' => [
                'balance_micro_usd' => (int) $account->balance_micro_usd,
                'spendable_micro_usd' => $account->spendableMicroUsd(),
            ],
            'checklist' => [
                'embed_enabled' => $this->themes->tryOnButtonEnabled($context->connection),
                'products_imported' => Product::query()->exists(),
                'first_generation' => Generation::query()
                    ->where('status', Generation::STATUS_SUCCEEDED)
                    ->exists(),
            ],
            'links' => [
                'dashboard' => $panelBase.'/'.$site->slug,
                'theme_editor' => sprintf(
                    self::THEME_EDITOR_TEMPLATE,
                    $shop,
                    (string) config(self::CFG_EXT_UID),
                    (string) config(self::CFG_EMBED_HANDLE),
                ),
            ],
        ]);
    }

}
