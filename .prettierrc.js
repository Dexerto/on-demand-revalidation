module.exports = {
  semi: true,
  singleQuote: true,
  trailingComma: "es5",
  tabWidth: 2,
  printWidth: 100,
  bracketSpacing: true,
  arrowParens: "avoid",
  endOfLine: "lf",
  overrides: [
    {
      files: "*.{ts,tsx}",
      options: {
        parser: "typescript",
      },
    },
  ],
};
