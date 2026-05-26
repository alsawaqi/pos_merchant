import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

/**
 * Vite config for the merchant portal SPA.
 *
 * Mirrors pos_admin's setup but bound to host port 5174 (pos_admin
 * uses 5175). Browser-facing HMR URL is read from VITE_DEV_SERVER_URL
 * so the laravel-vite-plugin's @viteHotFile points the SPA at the
 * right HMR socket — without this, the blade-rendered <script src>
 * tries to hit the container-internal port + breaks live reload.
 */
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const devServerUrl = env.VITE_DEV_SERVER_URL || 'http://localhost:5174';
    const hmrHost = env.VITE_HMR_HOST || 'localhost';
    const hmrPort = Number(env.VITE_HMR_PORT || 5174);
    const corsOrigins = (env.VITE_DEV_SERVER_CORS_ORIGINS || '')
        .split(',')
        .map((o) => o.trim())
        .filter(Boolean);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: [
                    'resources/views/**',
                    'routes/**',
                    'app/Http/**',
                ],
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': path.resolve(__dirname, 'resources/js'),
            },
        },
        server: {
            host: '0.0.0.0',
            port: Number(env.VITE_PORT || 5174),
            strictPort: true,
            origin: devServerUrl,
            hmr: {
                host: hmrHost,
                port: hmrPort,
                protocol: 'ws',
            },
            cors: corsOrigins.length > 0 ? { origin: corsOrigins } : true,
            watch: {
                usePolling: true,
                interval: 200,
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
