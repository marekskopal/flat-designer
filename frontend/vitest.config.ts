import angular from '@analogjs/vite-plugin-angular';
import {defineConfig} from 'vitest/config';

// The Angular plugin compiles components and services for TestBed specs (interceptors, guards); the
// framework-free engine specs (src/app/viewer/engine) run unchanged under it.
export default defineConfig({
    plugins: [angular()],
    resolve: {tsconfigPaths: true},
    test: {
        globals: true,
        environment: 'jsdom',
        setupFiles: ['src/test-setup.ts'],
        include: ['src/**/*.spec.ts'],
    },
});
