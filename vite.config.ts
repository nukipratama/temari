import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [
    laravel({
      input: ["resources/css/app.css", "resources/js/app.tsx"],
      refresh: true,
    }),
    react(),
    tailwindcss(),
  ],
  build: {
    rollupOptions: {
      output: {
        // Split heavy vendors into their own chunks so a page that doesn't use
        // charts/maps/animation doesn't pull the whole bundle. Without this
        // they all land in one large vendor chunk. Function form because Vite 8
        // / Rolldown rejects the object form of manualChunks.
        manualChunks(id) {
          if (id.includes("node_modules/chart.js") || id.includes("node_modules/react-chartjs-2")) {
            return "charts";
          }
          if (id.includes("node_modules/leaflet") || id.includes("node_modules/react-leaflet")) {
            return "maps";
          }
          if (id.includes("node_modules/framer-motion")) {
            return "motion";
          }
          if (id.includes("node_modules/lottie-react")) {
            return "lottie";
          }
        },
      },
    },
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "resources/js"),
    },
  },
  server: {
    host: "0.0.0.0",
    hmr: { host: "localhost" },
    watch: {
      ignored: ["**/storage/framework/views/**"],
    },
  },
});
