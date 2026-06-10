<?php

namespace Database\Seeders;

use App\Models\Employees;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);
        $this->call(UsersTableSeeder::class);
        $this->call(ClientSeeder::class);
        $this->call(ApparelTypeSeeder::class);
        $this->call(ApparelPartSeeder::class);
        $this->call(ApparelNecklineSeeder::class);
        $this->call(LabelTypeSeeder::class);
        $this->call(PatternTypeSeeder::class);
        $this->call(PrintMethodSeeder::class);
        $this->call(SpecialPrintSeeder::class);
        $this->call(ServiceTypeSeeder::class);
        $this->call(PrintLabelPlacements::class);
        $this->call(PantoneSeeder::class);
        $this->call(FabricSwatchSeeder::class);

        // Phase 7-B Bundle 1 — QA/Packer portal lookups + settings
        $this->call(RejectReasonSeeder::class);
        $this->call(QaChecklistItemSeeder::class);
        $this->call(PackingChecklistItemSeeder::class);
        $this->call(NotificationSettingsSeeder::class);

        $this->call(PricingSettingSeeder::class);
        $this->call(ApparelPatternPriceSeeder::class);
        $this->call(PlacementMeasurements::class);
    }
}
