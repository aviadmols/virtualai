<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The global AI control plane first (operations / models / global prompts)
        // so the resolver works out of the box, then the tenant demo data.
        $this->call(AiControlPlaneSeeder::class);
        $this->call(StoryboardPipelineSeeder::class);
        // The Kling catalog rides on the operations the two seeders above create.
        $this->call(KlingCatalogSeeder::class);
        $this->call(TenantDemoSeeder::class);
    }
}
