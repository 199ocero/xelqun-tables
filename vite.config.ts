import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.ts'],
        // Forms ships TS and Vue source, so it has to go through Vite.
        server: { deps: { inline: ['@xelqun/forms'] } },
    },
    lint: {
        ignorePatterns: ['vendor/**', 'node_modules/**'],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 100,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            'vendor/**',
            '.claude/**',
            'composer.json',
            'docs/**',
            'tests/Fixtures/**',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/xelqun-tables.css',
        },
    },
});
