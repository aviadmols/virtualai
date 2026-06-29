import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Filament panel themes (registered via ->viteTheme() in each PanelProvider).
                'resources/css/filament/platform/theme.css',
                'resources/css/filament/merchant/theme.css',
            ],
            refresh: true,
        }),
    ],
});
