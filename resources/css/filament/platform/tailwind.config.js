import preset from '../../../../vendor/filament/filament/tailwind.config.preset.js'

/** Platform panel Tailwind config — extends Filament's preset and scopes content
 *  to the platform Filament views + the shared to/* components. */
export default {
    presets: [preset],
    content: [
        './app/Filament/Platform/**/*.php',
        './resources/views/filament/platform/**/*.blade.php',
        './resources/views/components/to/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/bezhansalleh/filament-language-switch/resources/**/*.blade.php',
    ],
}
