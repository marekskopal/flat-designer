import {defineConfig} from 'vitest/config';

// Unit tests cover the framework-free geometry code (src/app/viewer/engine), so no Angular plugin
// or DOM is needed.
export default defineConfig({
    resolve: {tsconfigPaths: true},
    test: {
        globals: true,
        environment: 'node',
        include: ['src/**/*.spec.ts'],
    },
});
