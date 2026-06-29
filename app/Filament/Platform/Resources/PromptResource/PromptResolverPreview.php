<?php

namespace App\Filament\Platform\Resources\PromptResource;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Preview\OperationPreview;
use App\Domain\Platform\PlatformSiteQuery;
use App\Models\Site;
use Livewire\Component;
use Throwable;

/**
 * P5 — the RESOLVER-PREVIEW panel (mounted on the prompt Edit page).
 *
 * Given an (operation_key, optional site, optional product_type), it calls
 * AiOperationResolver::preview() and renders the WINNING model + prompt + the
 * resolution trace + a SAMPLE substitution. It is the read-only "which model +
 * prompt wins, and why" surface.
 *
 * G9 (template safety) — the substitution is OperationPreview::renderUserPrompt()
 * which is strtr ONLY (never Blade::render); the result is echoed through Blade's
 * {{ }} auto-escape into a read-only <pre> block (built in the Blade view), so an
 * injected {{placeholder}} renders verbatim and is never evaluated. No HTTP call,
 * no DB write — the 8a preview() already guarantees this; this component only
 * renders its result.
 *
 * G6 — the optional site picker reads cross-account sites through the AUDITED
 * PlatformSiteQuery seam (super-admin gated), never a bare Site::query().
 */
class PromptResolverPreview extends Component
{
    // === CONSTANTS ===
    private const VIEW = 'filament.platform.resources.prompt.resolver-preview';

    // Resolve states surfaced to the Blade (idle/resolved/no-match/error).
    public const STATE_IDLE = 'idle';
    public const STATE_RESOLVED = 'resolved';
    public const STATE_ERROR = 'error';

    // Sample variables used for the strtr substitution demo (non-sensitive, fixed).
    private const SAMPLE_VARS = [
        'product_title' => 'Classic Trench Coat',
        'product_type' => 'outerwear',
        'variant' => 'Beige / M',
        'height_cm' => '175',
        'brand' => 'Acme',
    ];

    // The "no site" sentinel for the picker (global / product_type-only resolution).
    private const SITE_NONE = '';

    // --- Livewire state (bound from the edit page) ---
    public string $operationKey = '';

    // Bound as a string from the <select>; '' = no site. Cast to int when resolving.
    public string $siteId = self::SITE_NONE;

    public ?string $productType = null;

    public string $state = self::STATE_IDLE;

    /** Mount with the prompt's operation_key + product_type pre-filled. */
    public function mount(string $operationKey, ?string $productType = null): void
    {
        $this->operationKey = $operationKey;
        $this->productType = $productType;
    }

    /** Re-resolve when the admin changes the operation / site / product type. */
    public function updated(): void
    {
        $this->state = self::STATE_IDLE;
    }

    /** Trigger the read-only preview (no HTTP, no write — see class docblock). */
    public function runPreview(): void
    {
        try {
            $this->preview();
            $this->state = self::STATE_RESOLVED;
        } catch (Throwable) {
            $this->state = self::STATE_ERROR;
        }
    }

    /**
     * The resolved preview (or null when not yet resolved / on error). Computed,
     * not stored, so it never serializes a model into Livewire state.
     */
    public function previewOrNull(): ?OperationPreview
    {
        if ($this->state !== self::STATE_RESOLVED || $this->operationKey === '') {
            return null;
        }

        try {
            return $this->preview();
        } catch (Throwable) {
            return null;
        }
    }

    /** The cross-account site options for the optional picker (audited seam). */
    public function siteOptions(): array
    {
        $options = [self::SITE_NONE => __('platform.resolver.input.site_none')];

        foreach (PlatformSiteQuery::withAccount()->orderBy('name')->get() as $site) {
            $options[(string) $site->getKey()] = $this->siteLabel($site);
        }

        return $options;
    }

    /** The fixed sample variables surfaced to the Blade for the substitution demo. */
    public function sampleVars(): array
    {
        return self::SAMPLE_VARS;
    }

    /** True when the last preview attempt errored (Blade state check — no class import). */
    public function isError(): bool
    {
        return $this->state === self::STATE_ERROR;
    }

    /** A resolution-trace outcome → badge tone (kept off the Blade; not a status machine). */
    public function outcomeTone(string $outcome): string
    {
        return match ($outcome) {
            'won' => 'success',
            'no_match' => 'danger',
            'skipped' => 'neutral',
            default => 'warn',
        };
    }

    public function render()
    {
        return view(self::VIEW, [
            'preview' => $this->previewOrNull(),
            'sampleVars' => $this->sampleVars(),
        ]);
    }

    /** The single resolve call — shared by runPreview() and previewOrNull(). */
    private function preview(): OperationPreview
    {
        $site = $this->siteId !== self::SITE_NONE
            ? PlatformSiteQuery::withAccount()->whereKey((int) $this->siteId)->first()
            : null;

        return app(AiOperationResolver::class)->preview(
            operationKey: $this->operationKey,
            site: $site instanceof Site ? $site : null,
            productType: $this->productType ?: null,
        );
    }

    /** "Account · Site" label for a cross-account site option. */
    private function siteLabel(Site $site): string
    {
        $account = $site->account?->name ?? ('#'.$site->account_id);

        return $account.' · '.$site->name;
    }
}
