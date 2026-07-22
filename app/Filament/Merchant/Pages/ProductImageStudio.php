<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Ai\AspectRatios;
use App\Domain\Ai\ImageQualities;
use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Domain\ProductImages\BatchResult;
use App\Domain\ProductImages\FixProductImage;
use App\Domain\ProductImages\ProductImageReview;
use App\Domain\ProductImages\RegenerateProductImage;
use App\Domain\ProductImages\ReviewTile;
use App\Domain\ProductImages\StartProductImageBatch;
use App\Domain\Shopify\Media\MediaPlacement;
use App\Domain\Shopify\Media\PushProductMedia;
use App\Domain\Shopify\Media\PushResult;
use App\Domain\Shopify\Media\ShopifyMediaItem;
use App\Filament\Merchant\Concerns\ResolvesShopAccount;
use App\Models\AiOperation;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\StylePreset;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Product Image Studio (Phase 4) — bulk AI image generation + merchant review.
 *
 * Three surfaces on one screen:
 *  1. GENERATE — pick the operation (packshot / on-model), which of the product's own photos to
 *     transform, and the products. Before confirming, the merchant sees the ADVISORY estimate:
 *     how many images, roughly what it costs, and their spendable balance.
 *  2. PROGRESS — the running batch's live counters (polled; the numbers come from the batch ROW,
 *     which the workers own — never from the queue).
 *  3. REVIEW — the finished images with short-lived signed URLs: approve / reject, one at a time
 *     or in bulk, plus an explicit Regenerate (a NEW, separately-charged asset — but its
 *     idempotency is decided by RegenerateProductImage in the domain, never by this page: a
 *     double-clicked button collapses to ONE asset, ONE render, ONE charge).
 *
 * The copy is blunt on purpose (`product_images.charge_notice`): generation is charged when the
 * AI SUCCEEDS, and rejecting an image afterwards does not refund it — the model already ran.
 *
 * Tenant-safety: the account/site come from the CURRENT SHOP TENANT (ResolvesShopAccount), and
 * every read runs through the BelongsToAccount global scope + an explicit site filter — a foreign
 * account's product or asset simply is not there (fail closed). No withoutGlobalScopes().
 *
 * The page never calls a model and never writes a ledger row: it plans, queues, and reviews.
 */
class ProductImageStudio extends Page
{
    use ResolvesShopAccount;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'nav.marketing';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.merchant.pages.product-image-studio';

    // The live progress poll — the batch counters are written by the queue.
    protected static ?string $pollingInterval = '5s';

    // How many finished images the review grid shows at once.
    private const GRID_LIMIT = 60;

    // How many products the picker searches / offers.
    private const PRODUCT_SEARCH_LIMIT = 50;

    // Form field names.
    private const FIELD_OPERATION = 'operation_key';

    private const FIELD_STYLE = 'style_id';

    private const FIELD_STYLE_LABEL = 'product_images.generate.style';

    // The visual before/after style picker (sample ↔ reference) rendered inside the Generate modal.
    private const STYLE_PICKER_VIEW = 'filament.merchant.components.style-picker';

    private const FIELD_SOURCE = 'source_pick';

    private const FIELD_PRODUCTS = 'product_ids';

    private const FIELD_ESTIMATE = 'estimate';

    // Per-generation choices (Image Studio v2).
    private const FIELD_NOTES = 'notes';

    private const FIELD_ASPECT = 'aspect_ratio';

    private const FIELD_QUALITY = 'image_quality';

    private const FIELD_NOTES_LABEL = 'product_images.generate.notes';

    private const FIELD_NOTES_HELP = 'product_images.generate.notes_help';

    private const FIELD_ASPECT_LABEL = 'product_images.generate.aspect';

    private const FIELD_QUALITY_LABEL = 'product_images.generate.quality';

    // i18n keys — never a literal in the page.
    private const TITLE = 'product_images.title';

    private const NAV_LABEL = 'product_images.nav';

    private const GENERATE_ACTION = 'product_images.generate.action';

    private const GENERATE_HEADING = 'product_images.generate.heading';

    private const GENERATE_SUB = 'product_images.generate.sub';

    private const GENERATE_CTA = 'product_images.generate.cta';

    private const FIELD_OPERATION_LABEL = 'product_images.generate.operation';

    private const FIELD_SOURCE_LABEL = 'product_images.generate.source';

    private const FIELD_SOURCE_HELP = 'product_images.generate.source_help';

    private const FIELD_PRODUCTS_LABEL = 'product_images.generate.products';

    private const FIELD_PRODUCTS_HELP = 'product_images.generate.products_help';

    private const ESTIMATE_LABEL = 'product_images.generate.estimate';

    private const ESTIMATE_EMPTY = 'product_images.generate.estimate_empty';

    private const ESTIMATE_LINE = 'product_images.generate.estimate_line';

    private const ESTIMATE_SHORT = 'product_images.generate.estimate_short';

    private const APPROVE_ALL_ACTION = 'product_images.review.approve_all';

    private const REJECT_ALL_ACTION = 'product_images.review.reject_all';

    private const APPROVE_ALL_HEADING = 'product_images.review.approve_all_heading';

    private const REJECT_ALL_HEADING = 'product_images.review.reject_all_heading';

    private const REJECT_ALL_SUB = 'product_images.review.reject_all_sub';

    private const NOTIFY_QUEUED = 'product_images.notify.queued';

    private const NOTIFY_QUEUED_BODY = 'product_images.notify.queued_body';

    private const NOTIFY_SKIPPED = 'product_images.notify.skipped';

    private const NOTIFY_DENIED = 'product_images.notify.denied';

    private const NOTIFY_DENIED_BODY = 'product_images.notify.denied_body';

    private const NOTIFY_NOTHING = 'product_images.notify.nothing';

    private const NOTIFY_INACTIVE = 'product_images.notify.inactive';

    private const NOTIFY_APPROVED = 'product_images.notify.approved';

    private const NOTIFY_REJECTED = 'product_images.notify.rejected';

    private const NOTIFY_DELETED = 'product_images.notify.deleted';

    // Deleting an image that is live in the store would orphan the storefront copy — undo first.
    private const NOTIFY_DELETE_IN_STORE = 'product_images.notify.delete_in_store';

    // An image that is LIVE in the store cannot be rejected — undo the push first.
    private const NOTIFY_REJECT_PUSHED = 'product_images.notify.reject_pushed';

    private const NOTIFY_BULK = 'product_images.notify.bulk';

    private const NOTIFY_REGENERATING = 'product_images.notify.regenerating';

    private const NOTIFY_STILL_RENDERING = 'product_images.notify.still_rendering';

    // --- Phase 5: the store rail (push / re-push / undo). Every one of these is FREE. ---
    private const PUSH_ACTION = 'pushMedia';

    private const PUSH_ARG_ASSET = 'asset';

    private const FIELD_PLACEMENT = 'placement';

    private const FIELD_POSITION = 'position';

    private const FIELD_REPLACE = 'replace_media_id';

    private const PUSH_HEADING = 'product_images.push.heading';

    private const PUSH_SUB = 'product_images.push.sub';

    private const PUSH_CTA = 'product_images.push.cta';

    private const PLACEMENT_LABEL = 'product_images.push.placement';

    private const PLACEMENT_HELP = 'product_images.push.placement_help';

    private const POSITION_LABEL = 'product_images.push.position';

    private const POSITION_HELP = 'product_images.push.position_help';

    private const POSITION_OPTION = 'product_images.push.position_option';

    private const POSITION_OPTION_LAST = 'product_images.push.position_option_last';

    private const REPLACE_LABEL = 'product_images.push.replace';

    private const REPLACE_HELP = 'product_images.push.replace_help';

    private const REPLACE_OPTION = 'product_images.push.replace_option';

    private const REPLACE_OPTION_UNTITLED = 'product_images.push.replace_option_untitled';

    private const PUSH_WARNING = 'product_images.push.warning';

    private const PUSH_GALLERY_EMPTY = 'product_images.push.gallery_empty';

    private const PLACEMENT_OPTION_PREFIX = 'product_images.placement.';

    private const NOTIFY_PUSHING = 'product_images.notify.pushing';

    private const NOTIFY_REPUSHING = 'product_images.notify.repushing';

    private const NOTIFY_UNDOING = 'product_images.notify.undoing';

    // --- Per-image edit modals (Image Studio v2): guided regenerate + image-to-image fix. Both
    // mint a NEW, separately-charged asset; the money guard lives in the domain services, never
    // here. Mounted per tile with the asset id as an argument, like pushMedia. ---
    private const UPDATE_PROMPT_ACTION = 'updatePrompt';

    private const FIX_IMAGE_ACTION = 'fixImage';

    private const FIELD_INSTRUCTION = 'instruction';

    private const UPDATE_PROMPT_HEADING = 'product_images.update_prompt.heading';

    private const UPDATE_PROMPT_SUB = 'product_images.update_prompt.sub';

    private const UPDATE_PROMPT_CTA = 'product_images.update_prompt.cta';

    private const FIX_HEADING = 'product_images.fix.heading';

    private const FIX_SUB = 'product_images.fix.sub';

    private const FIX_CTA = 'product_images.fix.cta';

    private const FIX_INSTRUCTION_LABEL = 'product_images.fix.instruction';

    private const FIX_INSTRUCTION_HELP = 'product_images.fix.instruction_help';

    private const PUSH_DENIED_KEYS = [
        PushResult::REASON_NOT_APPROVED => 'product_images.notify.push_not_approved',
        PushResult::REASON_ALREADY_PUSHED => 'product_images.notify.push_already',
        PushResult::REASON_IN_FLIGHT => 'product_images.notify.push_in_flight',
        PushResult::REASON_NOT_SHOPIFY => 'product_images.notify.push_not_shopify',
        PushResult::REASON_NOTHING_TO_UNDO => 'product_images.notify.undo_nothing',
        PushResult::REASON_NOT_FOUND => 'product_images.notify.nothing',
    ];

    /** The review filter chip (null = every finished image). */
    public ?string $reviewFilter = null;

    /** Per-request memo of a product's live Shopify gallery (the chooser re-renders on `live()`). */
    private array $galleryCache = [];

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** The batch still running for this shop (null when nothing is in flight). */
    public function activeBatch(): ?ProductImageBatch
    {
        return ProductImageBatch::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->whereIn('status', [ProductImageBatch::STATUS_PENDING, ProductImageBatch::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    /** The most recently finished batch (its images are what the grid shows first). */
    public function lastBatch(): ?ProductImageBatch
    {
        return ProductImageBatch::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->latest('id')
            ->first();
    }

    /** Per-review-state counts for the filter chips. @return array<string,int> */
    public function counts(): array
    {
        return app(ProductImageReview::class)->counts($this->shopSite());
    }

    /**
     * The review grid — finished images, newest first, each with a short-lived SIGNED url.
     *
     * @return Collection<int,ReviewTile>
     */
    public function tiles(): Collection
    {
        $media = app(MediaStorage::class);
        $snapshotted = app(PushProductMedia::class)->snapshottedProductIds($this->shopSite());

        return app(ProductImageReview::class)
            ->grid($this->shopSite(), null, $this->reviewFilter, self::GRID_LIMIT)
            ->map(fn (ProductAsset $asset): ReviewTile => ReviewTile::from($asset, $media, $snapshotted));
    }

    /**
     * The in-flight images (queued or rendering) for the live "in progress" strip. Kept flat
     * (id/name/status) so the view signs nothing and reads no model — the page polls, so the
     * strip empties itself as each image finishes and moves into the review grid below.
     *
     * @return Collection<int,array{id:int,name:string,status:string}>
     */
    public function processingTiles(): Collection
    {
        return app(ProductImageReview::class)
            ->processing($this->shopSite(), self::GRID_LIMIT)
            ->map(fn (ProductAsset $asset): array => [
                'id' => (int) $asset->getKey(),
                'name' => (string) ($asset->product?->name ?? ''),
                'status' => (string) $asset->status,
            ]);
    }

    /** The merchant's spendable credit (balance − in-flight reservations), in micro-USD. */
    public function spendableMicroUsd(): int
    {
        return $this->shopAccount()->spendableMicroUsd();
    }

    public function filterBy(?string $reviewStatus): void
    {
        $this->reviewFilter = in_array($reviewStatus, ProductAsset::REVIEW_STATUSES, true)
            ? $reviewStatus
            : null;
    }

    /** Approve ONE image (guarded transition + activity event on the model). */
    public function approve(int $assetId): void
    {
        if (app(ProductImageReview::class)->approve($this->shopSite(), $assetId)) {
            Notification::make()->success()->title(__(self::NOTIFY_APPROVED))->send();
        }
    }

    /**
     * Reject ONE image. It is NOT a refund — the generation already ran and was charged.
     *
     * An image that is LIVE in the store cannot be rejected: the panel would then say "rejected"
     * about an image the shopper is still looking at. The merchant undoes the push first — that is
     * the action that actually takes it down — and this says so, plainly.
     */
    public function reject(int $assetId): void
    {
        $review = app(ProductImageReview::class);

        if ($review->isBlockedByStore($this->shopSite(), $assetId)) {
            Notification::make()->warning()->title(__(self::NOTIFY_REJECT_PUSHED))->send();

            return;
        }

        if ($review->reject($this->shopSite(), $assetId)) {
            Notification::make()->success()->title(__(self::NOTIFY_REJECTED))->send();
        }
    }

    /**
     * Delete ONE finished image for good. Not a refund — the AI already ran. An image that is
     * live in the store is refused (the merchant undoes the push first, exactly like reject), so
     * deleting can never orphan a storefront copy.
     */
    public function deleteAsset(int $assetId): void
    {
        $review = app(ProductImageReview::class);

        if ($review->isBlockedByStore($this->shopSite(), $assetId)) {
            Notification::make()->warning()->title(__(self::NOTIFY_DELETE_IN_STORE))->send();

            return;
        }

        if ($review->delete($this->shopSite(), $assetId)) {
            Notification::make()->success()->title(__(self::NOTIFY_DELETED))->send();
        }
    }

    /**
     * Regenerate ONE image: a NEW, separately-charged asset — and the ONE place the deterministic
     * key is meant to vary.
     *
     * The page does NOT decide the client_request_id. It cannot: an id minted here (per CLICK)
     * would make a double-clicked button pay twice. RegenerateProductImage derives it from the
     * merchant's INTENT, so two clicks collapse to one asset, one render, one charge — the guard
     * lives in the domain, where a button cannot bypass it.
     */
    public function regenerate(int $assetId): void
    {
        $this->notifyResult(
            app(RegenerateProductImage::class)->handle($this->shopSite(), $assetId)
        );
    }

    /**
     * PUSH an approved image into the store's product media, at the placement the merchant picks.
     *
     * Mounted per tile with the asset id as an ARGUMENT (`mountAction('pushMedia', {asset: N})`),
     * so the chooser can show that product's REAL gallery — the merchant picks an existing slot or
     * an existing image, never a number they guessed.
     *
     * Nothing here is a money path: a push reserves nothing and charges nothing. The AI already
     * ran and was paid for when the image succeeded.
     */
    public function pushMediaAction(): Action
    {
        return Action::make(self::PUSH_ACTION)
            ->label(__(self::PUSH_CTA))
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading(__(self::PUSH_HEADING))
            ->modalDescription(__(self::PUSH_SUB))
            ->modalSubmitActionLabel(__(self::PUSH_CTA))
            ->form(fn (array $arguments): array => $this->placementForm($this->argAsset($arguments)))
            ->action(function (array $arguments, array $data): void {
                $mode = (string) ($data[self::FIELD_PLACEMENT] ?? MediaPlacement::MODE_APPEND);

                $this->notifyPush(
                    app(PushProductMedia::class)->push(
                        $this->shopSite(),
                        $this->argAsset($arguments),
                        MediaPlacement::fromInput(
                            $mode,
                            isset($data[self::FIELD_POSITION]) ? (int) $data[self::FIELD_POSITION] : null,
                            $data[self::FIELD_REPLACE] ?? null,
                        ),
                    ),
                    self::NOTIFY_PUSHING,
                );
            });
    }

    /**
     * UPDATE PROMPT — a guided regenerate: the merchant edits the art-direction note (prefilled
     * from the source's batch note) and the image is regenerated from the ORIGINAL product photo
     * with the new note. A NEW, separately-charged asset; the money guard (the intent id + the
     * note folded into the key) lives in RegenerateProductImage, never in this modal.
     */
    public function updatePromptAction(): Action
    {
        return Action::make(self::UPDATE_PROMPT_ACTION)
            ->label(__(self::UPDATE_PROMPT_CTA))
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(__(self::UPDATE_PROMPT_HEADING))
            ->modalDescription(__(self::UPDATE_PROMPT_SUB))
            ->modalSubmitActionLabel(__(self::UPDATE_PROMPT_CTA))
            ->form(fn (array $arguments): array => [
                Textarea::make(self::FIELD_NOTES)
                    ->label(__(self::FIELD_NOTES_LABEL))
                    ->helperText(__(self::FIELD_NOTES_HELP))
                    ->rows(3)
                    ->maxLength(500)
                    ->default($this->noteFor($this->argAsset($arguments))),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->notifyResult(
                    app(RegenerateProductImage::class)->handle(
                        $this->shopSite(),
                        $this->argAsset($arguments),
                        (string) ($data[self::FIELD_NOTES] ?? ''),
                    ),
                );
            });
    }

    /**
     * FIX IMAGE — an image-to-image correction of the CURRENT result: the merchant types what to
     * change and the AI edits THIS exact image (not a fresh take). A NEW, separately-charged asset;
     * the money guard (intent id + stable source hash) lives in FixProductImage.
     */
    public function fixImageAction(): Action
    {
        return Action::make(self::FIX_IMAGE_ACTION)
            ->label(__(self::FIX_CTA))
            ->icon('heroicon-o-wrench-screwdriver')
            ->modalHeading(__(self::FIX_HEADING))
            ->modalDescription(__(self::FIX_SUB))
            ->modalSubmitActionLabel(__(self::FIX_CTA))
            ->form(fn (array $arguments): array => [
                Textarea::make(self::FIELD_INSTRUCTION)
                    ->label(__(self::FIX_INSTRUCTION_LABEL))
                    ->helperText(__(self::FIX_INSTRUCTION_HELP))
                    ->rows(3)
                    ->maxLength(500)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->notifyResult(
                    app(FixProductImage::class)->handle(
                        $this->shopSite(),
                        $this->argAsset($arguments),
                        (string) ($data[self::FIELD_INSTRUCTION] ?? ''),
                    ),
                );
            });
    }

    /** The source asset's current batch note, for the Update-prompt prefill (site-scoped, fail closed). */
    private function noteFor(int $assetId): string
    {
        return (string) (ProductAsset::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->whereKey($assetId)
            ->first()?->batch?->notes ?? '');
    }

    /**
     * RE-PUSH a failed image: the UPLOAD is retried, at the same placement — the AI is NEVER run
     * again and no credit is consumed. (If Shopify had already minted the media before the
     * failure, the pusher resumes it instead of creating a second copy.)
     */
    public function rePush(int $assetId): void
    {
        $this->notifyPush(
            app(PushProductMedia::class)->rePush($this->shopSite(), $assetId),
            self::NOTIFY_REPUSHING,
        );
    }

    /**
     * UNDO — restore this product's ORIGINAL images: the originals come back (from our own copy),
     * the original order and main image are restored, and everything Vsio added is removed.
     * Free, and idempotent.
     */
    public function undoProductMedia(int $productId): void
    {
        $this->notifyPush(
            app(PushProductMedia::class)->undo($this->shopSite(), $productId),
            self::NOTIFY_UNDOING,
        );
    }

    /** The Generate action — the advisory estimate lives INSIDE the confirmation form. */
    public function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__(self::GENERATE_ACTION))
            ->icon('heroicon-o-sparkles')
            ->modalHeading(__(self::GENERATE_HEADING))
            ->modalDescription(__(self::GENERATE_SUB))
            ->modalSubmitActionLabel(__(self::GENERATE_CTA))
            ->form([
                // The VISUAL style picker: each approved Image-Studio preset as a Before/After card
                // (its uploaded reference ↔ the generated sample). Replaces the text dropdown when
                // styles exist; clicking a card selects that style's id (its base op + prompt).
                ViewField::make(self::FIELD_STYLE)
                    ->label(__(self::FIELD_STYLE_LABEL))
                    ->view(self::STYLE_PICKER_VIEW)
                    ->viewData(fn (): array => ['styles' => $this->styleCards()])
                    ->default(fn (): ?int => array_key_first($this->styleOptions()))
                    ->visible(fn (): bool => $this->styleOptions() !== [])
                    ->live(),

                // Fallback: the raw operation picker, shown only when no approved styles exist yet.
                Select::make(self::FIELD_OPERATION)
                    ->label(__(self::FIELD_OPERATION_LABEL))
                    ->options($this->operationOptions())
                    ->default(AiOperation::KEY_PACKSHOT_GENERATION)
                    ->visible(fn (): bool => $this->styleOptions() === [])
                    ->required(fn (): bool => $this->styleOptions() === [])
                    ->live(),

                Select::make(self::FIELD_SOURCE)
                    ->label(__(self::FIELD_SOURCE_LABEL))
                    ->helperText(__(self::FIELD_SOURCE_HELP))
                    ->options($this->sourceOptions())
                    ->default(ProductImageBatch::SOURCE_MAIN)
                    ->required()
                    ->live(),

                Select::make(self::FIELD_ASPECT)
                    ->label(__(self::FIELD_ASPECT_LABEL))
                    ->options($this->aspectOptions())
                    ->default('')
                    ->native(false),

                Select::make(self::FIELD_QUALITY)
                    ->label(__(self::FIELD_QUALITY_LABEL))
                    ->options($this->qualityOptions())
                    ->default('')
                    ->native(false),

                // Free-text art direction (e.g. "background #f5f5f0, softer shadow"). Appended to
                // the prompt as DATA (strtr, never evaluated); empty leaves the style prompt as-is.
                Textarea::make(self::FIELD_NOTES)
                    ->label(__(self::FIELD_NOTES_LABEL))
                    ->helperText(__(self::FIELD_NOTES_HELP))
                    ->rows(3)
                    ->maxLength(500),

                Select::make(self::FIELD_PRODUCTS)
                    ->label(__(self::FIELD_PRODUCTS_LABEL))
                    ->helperText(__(self::FIELD_PRODUCTS_HELP))
                    ->options(fn (): array => $this->productOptions())
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->live(),

                Placeholder::make(self::FIELD_ESTIMATE)
                    ->label(__(self::ESTIMATE_LABEL))
                    ->content(fn (Get $get): string => $this->estimateLine($get)),
            ])
            ->action(function (array $data): void {
                [$operationKey, $styleId] = $this->resolveStyle($data);

                $result = app(StartProductImageBatch::class)->handle(
                    site: $this->shopSite(),
                    productIds: array_map('intval', (array) ($data[self::FIELD_PRODUCTS] ?? [])),
                    operationKey: $operationKey,
                    sourcePick: (string) $data[self::FIELD_SOURCE],
                    styleId: $styleId,
                    notes: $data[self::FIELD_NOTES] ?? null,
                    aspectRatio: $data[self::FIELD_ASPECT] ?? null,
                    imageQuality: $data[self::FIELD_QUALITY] ?? null,
                );

                $this->notifyResult($result);
            });
    }

    /**
     * The chosen [operationKey, styleId]. A selected style supplies BOTH its base operation and
     * its id; otherwise the raw operation fallback is used with no style. @return array{0:string,1:?int}
     */
    private function resolveStyle(array $data): array
    {
        $styleId = ($data[self::FIELD_STYLE] ?? null) !== null ? (int) $data[self::FIELD_STYLE] : null;

        if ($styleId !== null) {
            $preset = StylePreset::find($styleId);
            if ($preset !== null) {
                return [(string) $preset->operation_key, $styleId];
            }
        }

        return [(string) ($data[self::FIELD_OPERATION] ?? AiOperation::KEY_PACKSHOT_GENERATION), null];
    }

    /** The approved Image-Studio styles (id => name) for the picker. @return array<int,string> */
    private function styleOptions(): array
    {
        return StylePreset::query()
            ->approvedForOperations(StylePreset::SURFACE_OPERATIONS[StylePreset::SURFACE_IMAGE_STUDIO])
            ->pluck('name', 'id')->all();
    }

    /**
     * The approved Image-Studio styles as Before/After cards: id, name, base-op label, and
     * short-lived signed URLs for the generated SAMPLE (after) + the uploaded REFERENCE (before).
     * A card with no sample yet shows the reference alone.
     *
     * @return array<int,array<string,mixed>>
     */
    private function styleCards(): array
    {
        $media = app(MediaStorage::class);

        return StylePreset::query()
            ->approvedForOperations(StylePreset::SURFACE_OPERATIONS[StylePreset::SURFACE_IMAGE_STUDIO])
            ->get(['id', 'name', 'operation_key', 'sample_image_path', 'reference_image_path'])
            ->map(fn (StylePreset $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'operation' => __('product_images.operation.'.$p->operation_key),
                'after' => $media->signedUrl($p->sample_image_path),
                'before' => $media->signedUrl($p->reference_image_path),
            ])
            ->all();
    }

    /** Approve every image still awaiting review. */
    public function approveAllAction(): Action
    {
        return Action::make('approveAll')
            ->label(__(self::APPROVE_ALL_ACTION))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => $this->counts()[ProductAsset::REVIEW_AWAITING] > 0)
            ->requiresConfirmation()
            ->modalHeading(__(self::APPROVE_ALL_HEADING))
            ->action(function (): void {
                $moved = app(ProductImageReview::class)->approveAwaiting($this->shopSite());

                Notification::make()->success()->title(__(self::NOTIFY_BULK, ['count' => $moved]))->send();
            });
    }

    /** Reject every image still awaiting review (still no refund — the AI already ran). */
    public function rejectAllAction(): Action
    {
        return Action::make('rejectAll')
            ->label(__(self::REJECT_ALL_ACTION))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->counts()[ProductAsset::REVIEW_AWAITING] > 0)
            ->requiresConfirmation()
            ->modalHeading(__(self::REJECT_ALL_HEADING))
            ->modalDescription(__(self::REJECT_ALL_SUB))
            ->action(function (): void {
                $moved = app(ProductImageReview::class)->rejectAwaiting($this->shopSite());

                Notification::make()->success()->title(__(self::NOTIFY_BULK, ['count' => $moved]))->send();
            });
    }

    /**
     * The placement chooser: append (safe default) / a specific position / replace an existing
     * image — the last two rendered from the product's REAL Shopify gallery.
     *
     * The destructive choices carry the promise the backend actually keeps: the originals are
     * copied BEFORE anything is touched, so "Restore original images" always works.
     *
     * @return array<int,mixed>
     */
    private function placementForm(int $assetId): array
    {
        $gallery = $this->galleryFor($assetId);
        $count = count($gallery);

        return [
            Select::make(self::FIELD_PLACEMENT)
                ->label(__(self::PLACEMENT_LABEL))
                ->helperText(__(self::PLACEMENT_HELP))
                ->options($this->placementOptions($count))
                ->default(MediaPlacement::MODE_APPEND)
                ->required()
                ->live(),

            Select::make(self::FIELD_POSITION)
                ->label(__(self::POSITION_LABEL))
                ->helperText(__(self::POSITION_HELP))
                ->options($this->positionOptions($count))
                ->default(MediaPlacement::FIRST_POSITION)
                ->required()
                ->visible(fn (Get $get): bool => $get(self::FIELD_PLACEMENT) === MediaPlacement::MODE_POSITION),

            Select::make(self::FIELD_REPLACE)
                ->label(__(self::REPLACE_LABEL))
                ->helperText(__(self::REPLACE_HELP))
                ->options($this->replaceOptions($gallery))
                ->required()
                ->visible(fn (Get $get): bool => $get(self::FIELD_PLACEMENT) === MediaPlacement::MODE_REPLACE),

            Placeholder::make('placement_warning')
                ->label('')
                ->content(fn (Get $get): string => $count === 0
                    ? __(self::PUSH_GALLERY_EMPTY)
                    : __(self::PUSH_WARNING))
                ->visible(fn (Get $get): bool => $count === 0
                    || $get(self::FIELD_PLACEMENT) !== MediaPlacement::MODE_APPEND),
        ];
    }

    /** A gallery we cannot read offers APPEND only — the one placement that needs no knowledge of it. */
    private function placementOptions(int $galleryCount): array
    {
        $modes = $galleryCount === 0
            ? [MediaPlacement::MODE_APPEND]
            : MediaPlacement::MODES;

        $options = [];

        foreach ($modes as $mode) {
            $options[$mode] = __(self::PLACEMENT_OPTION_PREFIX.$mode);
        }

        return $options;
    }

    /** Slots 1..N+1 (1 = the main image; N+1 = the end). @return array<int,string> */
    private function positionOptions(int $galleryCount): array
    {
        $options = [];
        $last = $galleryCount + 1;

        for ($slot = MediaPlacement::FIRST_POSITION; $slot <= $last; $slot++) {
            $options[$slot] = $slot === $last
                ? __(self::POSITION_OPTION_LAST, ['n' => $slot])
                : __(self::POSITION_OPTION, ['n' => $slot]);
        }

        return $options;
    }

    /** The product's current gallery, as "position — alt text". @return array<string,string> */
    private function replaceOptions(array $gallery): array
    {
        $options = [];

        foreach ($gallery as $item) {
            $options[$item->id] = $item->alt !== null
                ? __(self::REPLACE_OPTION, ['n' => $item->position, 'alt' => $item->alt])
                : __(self::REPLACE_OPTION_UNTITLED, ['n' => $item->position]);
        }

        return $options;
    }

    /**
     * The live Shopify gallery of the asset's product, memoized for the request (the placement
     * select is `live()`, so the form re-renders on every change — one API read is enough).
     *
     * @return array<int,ShopifyMediaItem>
     */
    private function galleryFor(int $assetId): array
    {
        if (array_key_exists($assetId, $this->galleryCache)) {
            return $this->galleryCache[$assetId];
        }

        $productId = (int) (ProductAsset::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->whereKey($assetId)
            ->value('product_id') ?? 0);

        return $this->galleryCache[$assetId] = $productId === 0
            ? []
            : app(PushProductMedia::class)->gallery($this->shopSite(), $productId);
    }

    /** @param array<string,mixed> $arguments */
    private function argAsset(array $arguments): int
    {
        return (int) ($arguments[self::PUSH_ARG_ASSET] ?? 0);
    }

    /** A push/undo refusal is a TYPED result -> the right notification. Never a 500. */
    private function notifyPush(PushResult $result, string $successKey): void
    {
        if ($result->wasDenied()) {
            Notification::make()
                ->warning()
                ->title(__(self::PUSH_DENIED_KEYS[$result->deniedReason] ?? self::NOTIFY_NOTHING))
                ->send();

            return;
        }

        Notification::make()->success()->title(__($successKey))->send();
    }

    /** The typed outcome of a batch request -> the right merchant notification (never a 500). */
    private function notifyResult(BatchResult $result): void
    {
        if ($result->wasDenied()) {
            $this->notifyDenial($result);

            return;
        }

        Notification::make()
            ->success()
            ->title(__(self::NOTIFY_QUEUED, ['count' => $result->queued]))
            ->body($result->skipped() > 0
                ? __(self::NOTIFY_SKIPPED, ['count' => $result->skipped()])
                : __(self::NOTIFY_QUEUED_BODY))
            ->send();
    }

    /** An out-of-credits / inactive / nothing-to-do batch is a TYPED result, never an error page. */
    private function notifyDenial(BatchResult $result): void
    {
        $notification = Notification::make()->warning();

        match ($result->deniedReason) {
            BatchResult::DENIED_INSUFFICIENT_CREDITS => $notification
                ->title(__(self::NOTIFY_DENIED))
                ->body(__(self::NOTIFY_DENIED_BODY, [
                    'needed' => $this->money($result->plan?->totalMicroUsd() ?? 0),
                    'have' => $this->money($result->plan?->spendableMicroUsd ?? 0),
                    'affordable' => $result->plan?->affordableCount() ?? 0,
                ])),
            BatchResult::DENIED_ACCOUNT_INACTIVE => $notification->title(__(self::NOTIFY_INACTIVE)),
            BatchResult::DENIED_STILL_RENDERING => $notification->title(__(self::NOTIFY_STILL_RENDERING)),
            default => $notification->title(__(self::NOTIFY_NOTHING)),
        };

        $notification->send();
    }

    /** The live estimate line inside the Generate form (advisory — it charges nothing). */
    private function estimateLine(Get $get): string
    {
        $productIds = array_map('intval', (array) ($get(self::FIELD_PRODUCTS) ?? []));

        if ($productIds === []) {
            return __(self::ESTIMATE_EMPTY);
        }

        $plan = app(StartProductImageBatch::class)->plan(
            site: $this->shopSite(),
            productIds: $productIds,
            operationKey: $this->operationFromGet($get),
            sourcePick: (string) $get(self::FIELD_SOURCE),
        );

        $line = __(self::ESTIMATE_LINE, [
            'count' => $plan->count(),
            'total' => $this->money($plan->totalMicroUsd()),
            'each' => $this->money($plan->estimatePerAssetMicroUsd),
            'balance' => $this->money($plan->spendableMicroUsd),
        ]);

        if (! $plan->affordable()) {
            $line .= ' '.__(self::ESTIMATE_SHORT, ['affordable' => $plan->affordableCount()]);
        }

        return $line;
    }

    /** micro-USD -> a human "$1.23" (integers in, formatted once at the boundary). */
    private function money(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }

    /** The operation the estimate should price: a selected style's base op, else the raw picker. */
    private function operationFromGet(Get $get): string
    {
        $styleId = $get(self::FIELD_STYLE);

        if ($styleId !== null && $styleId !== '') {
            $op = StylePreset::query()->whereKey((int) $styleId)->value('operation_key');
            if ($op !== null) {
                return (string) $op;
            }
        }

        return (string) ($get(self::FIELD_OPERATION) ?? AiOperation::KEY_PACKSHOT_GENERATION);
    }

    /** The two DB-managed product-image operations, labelled from the i18n catalog. @return array<string,string> */
    private function operationOptions(): array
    {
        $options = [];

        foreach (AiOperation::PRODUCT_IMAGE_KEYS as $key) {
            $options[$key] = __('product_images.operation.'.$key);
        }

        return $options;
    }

    /** @return array<string,string> */
    private function sourceOptions(): array
    {
        $options = [];

        foreach (ProductImageBatch::SOURCE_PICKS as $pick) {
            $options[$pick] = __('product_images.source.'.$pick);
        }

        return $options;
    }

    /** Aspect-ratio choices: "" = keep the style's default, then the curated ratios. @return array<string,string> */
    private function aspectOptions(): array
    {
        $options = ['' => __('ai_choices.aspect.default')];

        foreach (AspectRatios::OPTIONS as $value => $suffix) {
            $options[$value] = __('ai_choices.aspect.'.$suffix);
        }

        return $options;
    }

    /** Image-quality choices: "" = keep the style's default, then standard / high. @return array<string,string> */
    private function qualityOptions(): array
    {
        $options = ['' => __('ai_choices.quality.default')];

        foreach (ImageQualities::OPTIONS as $value => $suffix) {
            $options[$value] = __('ai_choices.quality.'.$suffix);
        }

        return $options;
    }

    /** The shop's ACTIVE products (account-scoped by the global scope). @return array<int,string> */
    private function productOptions(): array
    {
        return Product::query()
            ->where('site_id', $this->shopSite()->getKey())
            ->active()
            ->orderBy('name')
            ->limit(self::PRODUCT_SEARCH_LIMIT)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int,Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->generateAction(),
            $this->approveAllAction(),
            $this->rejectAllAction(),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'activeBatch' => $this->activeBatch(),
            'lastBatch' => $this->lastBatch(),
            'counts' => $this->counts(),
            'tiles' => $this->tiles(),
            'processing' => $this->processingTiles(),
            'spendable' => $this->money($this->spendableMicroUsd()),
        ];
    }
}
