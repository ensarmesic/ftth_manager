import js from '@eslint/js';
import globals from 'globals';

export default [
    { ignores: ['public/build/**', 'public/vendor/**', 'node_modules/**'] },
    {
        files: ['resources/js/**/*.js', 'scripts/**/*.mjs', 'e2e/**/*.mjs'],
        ...js.configs.recommended,
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: { ...globals.browser, ...globals.node },
        },
        rules: { 'no-unused-vars': ['error', { argsIgnorePattern: '^_' }], 'no-undef': 'error' },
    },
    {
        files: ['public/sw.js'],
        ...js.configs.recommended,
        languageOptions: { ecmaVersion: 'latest', globals: { ...globals.serviceworker, indexedDB: 'readonly' } },
    },
    {
        files: ['e2e/**/*.mjs'],
        rules: { 'no-undef': 'off', 'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }] },
    },
];
