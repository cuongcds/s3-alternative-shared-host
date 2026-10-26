module.exports = {
  content: [
    './application/views/admin/**/*.php',
    './assets/*.js',
  ],
  theme: {
    extend: {
      colors: {
        primary: 'var(--color-primary)',
        secondary: 'var(--color-secondary)',
        accent: 'var(--color-accent)',
        brand: {
          bg: 'var(--color-bg)',
          surface: 'var(--color-surface)',
          text: 'var(--color-text)',
          muted: 'var(--color-muted)',
          'muted-fg': 'var(--color-muted-foreground)',
          border: 'var(--color-border)',
          input: 'var(--color-input)',
        },
      },
    },
  },
  plugins: [],
};
