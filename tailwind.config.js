/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{js,jsx}'],
  important: '#polymart-ai-root',
  corePlugins: {
    preflight: false,
  },
  theme: {
    extend: {
      colors: {
        pmai: {
          primary: '#2271b1',
          'primary-dark': '#135e96',
          surface: '#ffffff',
          muted: '#646970',
          border: '#c3c4c7',
        },
      },
      fontFamily: {
        sans: [
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          'Oxygen-Sans',
          'Ubuntu',
          'Cantarell',
          '"Helvetica Neue"',
          'sans-serif',
        ],
      },
    },
  },
  plugins: [],
};
