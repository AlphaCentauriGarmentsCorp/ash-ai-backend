<?php

namespace Database\Seeders\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * The three products that have real product photography.
 *
 * Every other product in the catalogue still renders a grey placeholder box, because
 * image_path is null for all of them — these are the first with an actual shot, and
 * they are the reference for how a photographed product should look end to end.
 *
 * The app serves the shots from storage/app/public/products via the public/storage
 * symlink, so `php artisan storage:link` must have been run.
 *
 * But storage/app/public/.gitignore ignores '*', so anything left there is NOT in the
 * repo — on a fresh clone or a deploy the files would simply be absent and every card
 * would fall back to the grey placeholder, with nothing failing loudly to explain why.
 * So the masters ship in ./product-photos (a normal, committed directory) and this
 * seeder copies them into place. Seeding is therefore enough to stand the catalogue up
 * anywhere; the ignore rule keeps doing its job for genuinely user-uploaded content.
 *
 * updateOrCreate on the slug: re-running this must not duplicate a product or reset
 * stock that orders have already drawn down.
 */
class PhotographedProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->publishPhotos();

        $warehouse = (string) config('reefer.inventory.default_warehouse');
        $marketplace = (string) config('reefer.inventory.default_marketplace');

        foreach ($this->products() as $data) {
            $variants = $data['variants'];
            unset($data['variants']);

            $product = Product::updateOrCreate(
                ['slug' => $data['slug']],
                $data + ['marketplace' => $marketplace],
            );

            foreach ($variants as [$size, $sku, $onHand, $active, $weight, $width, $length, $shelf, $area]) {
                // `allocated` is NOT seeded. It means "units reserved against an
                // order that has not shipped" (ProductVariant: "Checkout writes
                // it."), so a constant here asserts reservations for orders that
                // never existed — and it feeds available = on_hand - allocated,
                // which is what the shop sells against. It stays at its column
                // default of 0 and is moved only by OrderController::reserveStock()
                // and OrderStock::apply().
                $variant = $product->variants()->firstOrNew(['size' => $size]);

                $variant->fill([
                    'sku' => $sku,
                    'is_active' => $active,
                    'weight_grams' => $weight,
                    'width_cm' => $width,
                    'length_cm' => $length,
                    'shelf_location' => $shelf,
                    'warehouse' => $warehouse,
                    'area' => $area,
                ]);

                // on_hand is an OPENING count, written once. Re-seeding used to
                // overwrite it, silently undoing stock that real orders had drawn
                // down — the opposite of what this seeder's own docblock promises.
                if (! $variant->exists) {
                    $variant->on_hand = $onHand;
                }

                $variant->save();
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    /**
     * Copy the committed masters into the public disk, so image_path resolves.
     *
     * Overwrites on every run: the repo is the source of truth for these three, and a
     * half-written or truncated file from an interrupted deploy should heal on reseed
     * rather than persist silently. Warns instead of throwing — a missing photo is a
     * cosmetic problem, and it should not be able to abort a catalogue seed.
     */
    private function publishPhotos(): void
    {
        $from = __DIR__.'/product-photos';
        $to = storage_path('app/public/products');

        if (! File::isDirectory($from)) {
            $this->command?->warn("No product-photos directory at {$from}; product images will fall back to placeholders.");

            return;
        }

        File::ensureDirectoryExists($to);

        foreach (File::files($from) as $photo) {
            File::copy($photo->getPathname(), $to.'/'.$photo->getFilename());
        }

        /*
         * file_exists(), not is_link()/is_dir(): storage:link makes a SYMLINK on Linux
         * but an NTFS JUNCTION on Windows, and PHP reports is_link() === false for a
         * junction — so an is_link() check cries wolf on every Windows dev machine.
         * file_exists() follows both and answers the only question that matters: will
         * asset('storage/...') resolve? The stat cache is cleared first because this
         * runs after writing files, and a cached miss would also be a false alarm.
         */
        $link = public_path('storage');
        clearstatcache(true, $link);

        if (! file_exists($link)) {
            $this->command?->warn('public/storage is missing — run `php artisan storage:link` or the photos will 404.');
        }
    }

    private function products(): array
    {
        // Figures taken from the ERP's own inventory export, so the two systems agree
        // from the first sync: SKU, price, per-size weight, flat dimensions, shelf and
        // storage area and opening on-hand quantities.
        //
        // The ERP writes MEDIUM/LARGE; the storefront keys carts and orders on the
        // short codes, so sizes are stored short and only rendered long.
        return [
            [
                'slug' => 'lick-responsibly',
                'product_code' => 'R001',
                'name' => 'Lick Responsibly',
                'audience' => 'unisex',
                'type' => 'tee',
                'price' => 650,
                'tag' => 'NEW',
                'blurb' => 'A strawberry cross-section, blown up and printed like an ad from a magazine nobody published.',
                'material' => '100% combed cotton, 220gsm. Optic white, pre-shrunk.',
                'fit_name' => 'BOX FIT',
                'fit_desc' => 'Wider through the chest with a cropped body. Size down if you want it closer to the frame.',
                'image_path' => 'products/lick-responsibly.jpg',
                'is_active' => true,
                'sort' => 1,
                'variants' => [
                    // size, sku, on_hand, active, weight, w, l, shelf, area
                    ['M',   'R001UM',   0, false, 320.0, 29.5, 34.0, 'A01', 'Storage 1'],
                    ['L',   'R001UL',  17, true,  337.0, 31.0, 34.0, 'B01', 'Storage 2'],
                    ['XL',  'R001UXL', 11, true,  342.0, 31.5, 34.0, 'C01', 'Storage 3'],
                    ['2XL', 'R001U2XL', 20, true,  374.0, 32.5, 36.0, 'D01', 'Storage 4'],
                ],
            ],
            [
                'slug' => 'dark-days',
                'product_code' => 'R002',
                'name' => 'Dark Days',
                'audience' => 'unisex',
                'type' => 'tee',
                'price' => 379,
                'tag' => 'NEW',
                'blurb' => 'Hand-scrawled on the chest: when days are dark, friends are few. Washed charcoal, nothing shouted.',
                'material' => '100% combed cotton, 220gsm. Garment-dyed charcoal, pre-shrunk.',
                'fit_name' => 'RELAXED FIT',
                'fit_desc' => 'Straight body with a slightly dropped shoulder. Take your true size; size up for a boxier drape.',
                'image_path' => 'products/dark-days.jpg',
                'is_active' => true,
                'sort' => 2,
                'variants' => [
                    ['M',   'R002UM',   7, true,  320.0, 29.5, 35.0, 'A02', 'Storage 5'],
                    ['L',   'R002UL',   0, false, 337.0, 32.0, 34.0, 'B02', 'Storage 6'],
                    ['XL',  'R002UXL',  5, true,  342.0, 31.5, 35.0, 'C02', 'Storage 7'],
                    ['2XL', 'R002U2XL', 10, true,  374.0, 32.5, 37.0, 'D02', 'Storage 8'],
                ],
            ],
            [
                'slug' => 'behemoth',
                'product_code' => 'R003',
                'name' => 'Behemoth',
                'audience' => 'unisex',
                'type' => 'tee',
                'price' => 379,
                'tag' => 'HEAVYWEIGHT',
                'blurb' => 'A ram skull rendered almost black on black. You only catch it when the light moves.',
                'material' => '240gsm heavyweight cotton. Deep black, enzyme-washed so it stays black.',
                'fit_name' => 'OVERSIZED FIT',
                'fit_desc' => 'Cut long with a dropped shoulder and a wide sleeve. Take your true size for the intended drape.',
                'image_path' => 'products/behemoth.jpg',
                'is_active' => true,
                'sort' => 3,
                'variants' => [
                    ['M',   'R003UM',   0, false, 320.0, 29.5, 36.0, 'A03', 'Storage 9'],
                    ['L',   'R003UL',   2, true,  337.0, 33.0, 34.0, 'B03', 'Storage 10'],
                    ['XL',  'R003UXL', 12, true,  342.0, 31.5, 36.0, 'C03', 'Storage 11'],
                    ['2XL', 'R003U2XL', 4, true,  374.0, 32.5, 38.0, 'D03', 'Storage 12'],
                ],
            ],
        ];
    }
}
