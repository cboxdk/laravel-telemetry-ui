import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The SPA is served by the package (AssetController) from public/build under a
// host-configured mount path, so every asset URL is relative ('./'): hashed
// chunks resolve against the importing module wherever the dashboard lives.
export default defineConfig({
    root: 'resources/app',
    base: './',
    plugins: [react()],
    build: {
        outDir: '../../public/build',
        emptyOutDir: true,
        manifest: true,
        sourcemap: false,
        chunkSizeWarningLimit: 900,
        rollupOptions: {
            input: 'resources/app/src/main.tsx',
            output: {
                manualChunks(id: string) {
                    if (id.includes('node_modules/echarts') || id.includes('node_modules/zrender')) return 'echarts';
                    if (id.includes('node_modules/@tanstack')) return 'tanstack';
                    if (id.includes('node_modules/react')) return 'react';
                    return undefined;
                },
            },
        },
    },
    test: {
        root: '.',
        environment: 'jsdom',
        include: ['resources/app/src/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/app/src/test/setup.ts'],
        css: false,
    },
});
