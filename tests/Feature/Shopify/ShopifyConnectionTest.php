<?php

namespace Tests\Feature\Shopify;

use App\Models\ActivityEvent;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * ShopifyConnection foundations: tenant isolation is fail-closed, the offline token is
 * encrypted at rest and hidden from serialization, the status machine is guarded (with
 * re-install re-activating the SAME row), and shop_domain is globally unique.
 */
class ShopifyConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): ShopifyConnection
    {
        $site = Site::factory()->create();

        return Tenant::run($site->account, fn () => ShopifyConnection::factory()->forSite($site)->create());
    }

    public function test_connections_are_tenant_scoped_and_fail_closed(): void
    {
        $connection = $this->connection();

        // Unbound: the global scope fails closed — nothing is visible.
        $this->assertSame(0, ShopifyConnection::query()->count());

        // The owning tenant sees it; a FOREIGN tenant does not.
        $this->assertSame(1, Tenant::run($connection->account, fn () => ShopifyConnection::query()->count()));

        $foreign = Site::factory()->create();
        $this->assertSame(0, Tenant::run($foreign->account, fn () => ShopifyConnection::query()->count()));
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $connection = $this->connection();

        $raw = (string) DB::table('shopify_connections')->where('id', $connection->id)->value('credentials');
        $token = (string) Tenant::run($connection->account, fn () => $connection->fresh()->accessToken());

        $this->assertNotSame('', $token);
        $this->assertStringStartsWith('shpat_', $token);
        // The plaintext token never touches the column.
        $this->assertStringNotContainsString($token, $raw);
        $this->assertStringNotContainsString('access_token', $raw);

        // And never leaves via serialization.
        $this->assertArrayNotHasKey('credentials', $connection->toArray());
    }

    public function test_uninstall_wipes_credentials_and_reinstall_reactivates_the_same_row(): void
    {
        $connection = $this->connection();

        Tenant::run($connection->account, function () use ($connection): void {
            $connection = $connection->fresh();
            $connection->transitionTo(ShopifyConnection::STATUS_UNINSTALLED);

            $this->assertSame(ShopifyConnection::STATUS_UNINSTALLED, $connection->status);
            $this->assertNull($connection->credentials);
            $this->assertNull($connection->accessToken());
            $this->assertNotNull($connection->uninstalled_at);

            // The trailing uninstall wrote its semantic timeline event.
            $this->assertSame(1, ActivityEvent::query()->where('kind', ActivityEvent::KIND_SHOPIFY_UNINSTALLED)->count());

            // Re-install re-activates the SAME row (shop_domain never duplicates).
            $connection->transitionTo(ShopifyConnection::STATUS_INSTALLED);
            $this->assertTrue($connection->isInstalled());
            $this->assertFalse($connection->needs_reauth);
            $this->assertSame(1, ActivityEvent::query()->where('kind', ActivityEvent::KIND_SHOPIFY_INSTALLED)->count());
        });
    }

    public function test_an_illegal_transition_throws(): void
    {
        $connection = $this->connection();

        $this->expectException(RuntimeException::class);

        Tenant::run($connection->account, fn () => $connection->fresh()->transitionTo(ShopifyConnection::STATUS_INSTALLED));
    }

    public function test_shop_domain_is_globally_unique(): void
    {
        $connection = $this->connection();
        $other = Site::factory()->create();

        $this->expectException(QueryException::class);

        Tenant::run($other->account, fn () => ShopifyConnection::factory()
            ->forSite($other)
            ->create(['shop_domain' => $connection->shop_domain]));
    }
}
