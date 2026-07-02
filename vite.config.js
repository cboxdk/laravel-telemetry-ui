import { defineConfig } from 'vite';

// Builds the self-contained dashboard bundle served by AssetController.
// public/telemetry-ui.css is hand-written and not part of this build.
export default defineConfig({
    // Lib mode does not statically replace process.env; without this the
    // bundle throws "process is not defined" in the browser (echarts).
    define: {
        'process.env.NODE_ENV': '"production"',
    },
    build: {
        outDir: 'public',
        emptyOutDir: false,
        copyPublicDir: false,
        lib: {
            entry: 'resources/js/telemetry-ui.js',
            name: 'TelemetryUi',
            formats: ['iife'],
            fileName: () => 'telemetry-ui.js',
        },
    },
});
