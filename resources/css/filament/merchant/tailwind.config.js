import preset from '../../../../vendor/filament/filament/tailwind.config.preset.js'

/** Merchant panel Tailwind config — extends Filament's preset and scopes content
 *  to the merchant Filament views + the shared to/* components.
 *
 *  COLOR FORMAT FIX: Filament's bundled preset declares every palette as
 *  `rgba(var(--x), <alpha-value>)` (legacy comma form), but the channels are
 *  injected SPACE-separated (e.g. `--primary-600: 79 70 229`). That mix renders
 *  the invalid `rgba(79 70 229, 1)` → transparent buttons/badges. We re-declare
 *  every scale in the modern slash form `rgb(var(--x) / <alpha-value>)`, which IS
 *  valid with space-separated channels, so primary/custom/etc. backgrounds resolve.
 */
const scale = (varName) => Object.fromEntries(
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => [
        shade,
        `rgb(var(--${varName}-${shade}) / <alpha-value>)`,
    ]),
)

export default {
    presets: [preset],
    content: [
        './app/Filament/Merchant/**/*.php',
        './resources/views/filament/merchant/**/*.blade.php',
        './resources/views/components/to/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/bezhansalleh/filament-language-switch/resources/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                custom: scale('c'),
                primary: scale('primary'),
                danger: scale('danger'),
                gray: scale('gray'),
                info: scale('info'),
                success: scale('success'),
                warning: scale('warning'),
            },
        },
    },
}
