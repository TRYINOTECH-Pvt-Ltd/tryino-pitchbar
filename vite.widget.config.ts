import { resolve } from 'node:path';
import preact from '@preact/preset-vite';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [preact()],
    resolve: {
        alias: {
            react: 'preact/compat',
            'react-dom/test-utils': 'preact/test-utils',
            'react-dom': 'preact/compat',
            'react/jsx-runtime': 'preact/jsx-runtime',
        },
    },
    publicDir: false,
    build: {
        target: 'es2017',
        outDir: 'public/widget',
        emptyOutDir: true,
        sourcemap: false,
        cssCodeSplit: false,
        minify: 'terser',
        terserOptions: {
            compress: {
                passes: 2,
                pure_funcs: ['console.log', 'console.debug'],
            },
        },
        rollupOptions: {
            input: resolve(__dirname, 'resources/widget/src/entry.tsx'),
            output: {
                format: 'iife',
                entryFileNames: 'widget.js',
                assetFileNames: 'widget.[ext]',
            },
        },
    },
});
