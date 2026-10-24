/** @type {import('tailwindcss').Config} */
// Los colores del producto NO viven aquí: son custom properties en src/index.css
// (tokens del tema) y src/styles/themes.css (los 5 presets), porque cambian en
// tiempo de ejecución con claro/oscuro y con el preset elegido — algo que la
// configuración de Tailwind no puede expresar. Tailwind está por el reset y por
// alguna utilidad de layout puntual; el vocabulario visual son las clases de
// index.css.
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {},
  },
  plugins: [],
};
