<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Credits\CreditMath;
use App\Domain\Credits\Payments\PurchaseInitiator;
use App\Filament\Merchant\Concerns\ResolvesShopAccount;
use App\Models\Account;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * M7 / A11 — Buy credits. The merchant picks a preset amount; the page asks
 * PurchaseInitiator to start a hosted PayPlus payment and redirects to pay. NOTHING
 * is credited here — the credit_ledger `purchase` row is written later by the
 * idempotent webhook on a confirmed `paid` (so a failed/cancelled return never
 * charges). The provider redirects back to this same page with a ?status flag so the
 * merchant sees success / cancel / error copy.
 *
 * Money is integer micro-USD at FACE VALUE (the 2.5x markup is earned on a generation,
 * never on a top-up). The preset amounts are UI choices (the dollar buttons), not money
 * math — the chosen amount passes straight through to the initiator. Tenant-safety: the
 * account is the CURRENT SHOP TENANT's (Filament::getTenant()->account), bound by the panel;
 * the initiator stamps the pending purchase under that account via Tenant::run.
 */
class BuyCredits extends Page
{
    use ResolvesShopAccount;

    // === CONSTANTS ===
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationGroup = 'nav.credits';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.merchant.pages.buy-credits';

    // Preset top-up amounts in whole USD — UI choices only (the buttons). The chosen
    // value converts to face-value micro-USD via CreditMath and passes to the initiator.
    public const PRESET_USD = [10, 25, 50, 100];

    // The return-status flag the provider redirects back with (read on mount).
    private const STATUS_SUCCESS = 'success';
    private const STATUS_CANCEL = 'cancel';
    private const STATUS_FAILURE = 'failure';

    // The provider name the webhook route is keyed by (callback target).
    private const WEBHOOK_PROVIDER = 'payplus';

    // i18n keys — never a literal in the page.
    private const TITLE = 'credits.buy.title';
    private const NAV_LABEL = 'credits.buy.nav';
    private const NOTIFY_SUCCESS = 'credits.buy.success';
    private const NOTIFY_FAILED = 'credits.buy.errors.failed';
    private const NOTIFY_INIT_ERROR = 'credits.buy.errors.init';
    private const NOTIFY_NO_AMOUNT = 'credits.buy.no_amount';

    /** The selected preset amount in whole USD (null until the merchant picks one). */
    public ?int $selectedUsd = null;

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

    /**
     * Read a provider return-status flag (?status=success|cancel|failure) and surface
     * the matching copy. The crediting itself is the webhook's job — this is only the
     * shopper-return feedback; a cancel/failure never charges (money-safety).
     */
    public function mount(): void
    {
        $status = request()->query('status');

        match ($status) {
            self::STATUS_SUCCESS => $this->notify(self::NOTIFY_SUCCESS, success: true),
            self::STATUS_CANCEL, self::STATUS_FAILURE => $this->notify(self::NOTIFY_FAILED, success: false),
            default => null,
        };
    }

    /** Select one preset (Livewire-driven so the chosen amount is server-owned). */
    public function selectAmount(int $usd): void
    {
        if (in_array($usd, self::PRESET_USD, true)) {
            $this->selectedUsd = $usd;
        }
    }

    /** The preset cards as render-ready descriptors (amount + display + selected flag). */
    public function presets(): array
    {
        return array_map(fn (int $usd): array => [
            'usd' => $usd,
            'display' => '$'.number_format($usd),
            'selected' => $this->selectedUsd === $usd,
        ], self::PRESET_USD);
    }

    /**
     * Start the top-up: convert the selected USD to face-value micro-USD, ask the
     * initiator to create a hosted payment page, and redirect to pay. A provider
     * refusal surfaces an error and persists nothing (the merchant retries).
     */
    public function checkout(): mixed
    {
        if ($this->selectedUsd === null) {
            $this->notify(self::NOTIFY_NO_AMOUNT, success: false);

            return null;
        }

        $amountMicroUsd = CreditMath::usdToMicro((float) $this->selectedUsd);

        try {
            $intent = app(PurchaseInitiator::class)->initiate(
                $this->account(),
                $amountMicroUsd,
                $this->returnContext(),
            );
        } catch (\Throwable) {
            $this->notify(self::NOTIFY_INIT_ERROR, success: false);

            return null;
        }

        if (! $intent->ok || $intent->redirectUrl === null) {
            $this->notify(self::NOTIFY_FAILED, success: false);

            return null;
        }

        // Hand off to the hosted payment page. Nothing is credited until the webhook
        // confirms `paid`.
        return $this->redirect($intent->redirectUrl);
    }

    /** The current shop tenant's account (drill-in-safe; see ResolvesShopAccount). */
    private function account(): Account
    {
        return $this->shopAccount();
    }

    /**
     * The provider redirect/callback URLs: success/cancel/failure return to THIS page
     * with a ?status flag for the merchant-facing copy; the callback is the signed
     * webhook that actually credits the ledger.
     *
     * @return array<string,string>
     */
    private function returnContext(): array
    {
        return [
            'success_url' => self::getUrl(['status' => self::STATUS_SUCCESS]),
            'cancel_url' => self::getUrl(['status' => self::STATUS_CANCEL]),
            'failure_url' => self::getUrl(['status' => self::STATUS_FAILURE]),
            'callback_url' => route('webhooks.credits.purchase', ['provider' => self::WEBHOOK_PROVIDER]),
        ];
    }

    /** One notification (success or danger) from an i18n key. */
    private function notify(string $key, bool $success): void
    {
        $notification = Notification::make()->title(__($key));

        $success ? $notification->success() : $notification->danger();

        $notification->send();
    }
}
