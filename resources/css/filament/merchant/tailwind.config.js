import preset from '../../../../vendor/filament/filament/tailwind.config.preset.js'

/** Merchant panel Tailwind config — extends Filament's preset and scopes content
 *  to the merchant Filament views + the shared to/* components. */
export default {
    presets: [preset],
    content: [
        './app/Filament/Merchant/**/*.php',
        './resources/views/filament/merchant/**/*.blade.php',
        './resources/views/components/to/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/bezhansalleh/filament-language-switch/resources/**/*.blade.php',
    ],
}
