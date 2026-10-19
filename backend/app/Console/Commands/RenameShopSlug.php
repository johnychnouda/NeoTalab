<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Support\ShopSlug;
use Illuminate\Console\Command;

/**
 * Changes a shop's address (/backoffice/{slug}). Run from the Railway API console. The old address
 * still opens: the dashboard sends the owner to the shop's current slug after sign-in.
 */
class RenameShopSlug extends Command
{
    protected $signature = 'shop:rename-slug {current : The shop\'s current slug} {new : The new slug (lowercase letters, digits and dashes)}';

    protected $description = 'Change a shop\'s dashboard address (slug)';

    public function handle(): int
    {
        $merchant = Merchant::where('slug', $this->argument('current'))->first();
        if (! $merchant) {
            $this->error('No shop has that slug.');

            return self::FAILURE;
        }

        $new = (string) $this->argument('new');
        if (! preg_match(ShopSlug::PATTERN, $new) || strlen($new) > 40) {
            $this->error('Use lowercase letters, digits and single dashes, up to 40 characters.');

            return self::FAILURE;
        }

        if (Merchant::where('slug', $new)->whereKeyNot($merchant->getKey())->exists()) {
            $this->error("{$new} is already used by another shop.");

            return self::FAILURE;
        }

        $merchant->forceFill(['slug' => $new])->save();
        $this->info("{$merchant->name}: /backoffice/{$new}");

        return self::SUCCESS;
    }
}
