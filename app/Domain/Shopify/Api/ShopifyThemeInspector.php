<?php

namespace App\Domain\Shopify\Api;

use App\Models\ShopifyConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShopifyThemeInspector — answers ONE question, best-effort: is the Vsio try-on button
 * live in the store's MAIN theme?
 *
 * Two ways a merchant enables it (either counts):
 *  - the APP EMBED block (trayon.liquid) toggled on -> an entry in the theme's
 *    config/settings_data.json `current.blocks` whose type carries our extension uid,
 *    not disabled;
 *  - the SECTION app block (trayon-button.liquid) added to the product template -> a
 *    block whose type carries the uid inside templates/product.json.
 *
 * Requires the read_themes scope. Best-effort by design: ANY failure (missing scope on
 * an older install, throttling, an unexpected shape) returns NULL — "unknown", never an
 * error — so the onboarding checklist renders an action instead of a lie.
 */
final class ShopifyThemeInspector
{
    // === CONSTANTS ===
    private const FILE_SETTINGS = 'config/settings_data.json';

    private const FILE_PRODUCT_TEMPLATE = 'templates/product.json';

    private const CFG_EXTENSION_UID = 'shopify.theme_extension.uid';

    private const KEY_CURRENT = 'current';

    private const KEY_BLOCKS = 'blocks';

    private const KEY_TYPE = 'type';

    private const KEY_DISABLED = 'disabled';

    private const KEY_SECTIONS = 'sections';

    private const LOG_UNAVAILABLE = 'shopify.theme_inspector.unavailable';

    // The main theme's two relevant files, read in ONE call.
    private const QUERY = <<<'GRAPHQL'
    query vsioThemeFiles($filenames: [String!]!) {
      themes(first: 1, roles: [MAIN]) {
        nodes {
          files(filenames: $filenames, first: 2) {
            nodes {
              filename
              body { ... on OnlineStoreThemeFileBodyText { content } }
            }
          }
        }
      }
    }
    GRAPHQL;

    public function __construct(
        private readonly ShopifyGraphQLClient $client,
    ) {}

    /** True/false when the theme could be read; null = unknown (never an error). */
    public function tryOnButtonEnabled(ShopifyConnection $connection): ?bool
    {
        $uid = (string) config(self::CFG_EXTENSION_UID);

        if ($uid === '') {
            return null;
        }

        try {
            $data = $this->client->query($connection, self::QUERY, [
                'filenames' => [self::FILE_SETTINGS, self::FILE_PRODUCT_TEMPLATE],
            ]);
        } catch (Throwable $e) {
            Log::info(self::LOG_UNAVAILABLE, [
                'shop_domain' => $connection->shop_domain,
                'exception' => $e::class,
            ]);

            return null;
        }

        $files = data_get($data, 'themes.nodes.0.files.nodes');

        if (! is_array($files)) {
            return null;
        }

        $byName = [];

        foreach ($files as $file) {
            $name = data_get($file, 'filename');
            $content = data_get($file, 'body.content');

            if (is_string($name) && is_string($content)) {
                $byName[$name] = $content;
            }
        }

        return $this->appEmbedEnabled($byName[self::FILE_SETTINGS] ?? null, $uid)
            || $this->productBlockPresent($byName[self::FILE_PRODUCT_TEMPLATE] ?? null, $uid);
    }

    /** The app-embed block: present in current.blocks with our uid and not disabled. */
    private function appEmbedEnabled(?string $settingsJson, string $uid): bool
    {
        $blocks = data_get(self::decode($settingsJson), self::KEY_CURRENT.'.'.self::KEY_BLOCKS);

        if (! is_array($blocks)) {
            return false;
        }

        foreach ($blocks as $block) {
            $type = data_get($block, self::KEY_TYPE);

            if (is_string($type)
                && str_contains($type, $uid)
                && data_get($block, self::KEY_DISABLED) !== true) {
                return true;
            }
        }

        return false;
    }

    /** The section app block: any product-template section block whose type carries our uid. */
    private function productBlockPresent(?string $templateJson, string $uid): bool
    {
        $sections = data_get(self::decode($templateJson), self::KEY_SECTIONS);

        if (! is_array($sections)) {
            return false;
        }

        foreach ($sections as $section) {
            $blocks = data_get($section, self::KEY_BLOCKS);

            if (! is_array($blocks)) {
                continue;
            }

            foreach ($blocks as $block) {
                $type = data_get($block, self::KEY_TYPE);

                if (is_string($type) && str_contains($type, $uid)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Theme JSON may open with comments Shopify tolerates; a parse failure is just false. */
    private static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
