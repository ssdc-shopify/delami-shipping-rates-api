import { defineConfig } from 'vite';

// Relative asset paths, so the same build works served from the domain root
// (local `npm run dev`) or from a subpath like /mock-storefront/ behind nginx.
export default defineConfig({
  base: './',
});
