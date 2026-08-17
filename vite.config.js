import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: "localhost",
        port: 5173,
        hmr: {
            host: "localhost",
        },
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
    preview: {
        host: "localhost",
        port: 4173,
    },
});
