import * as phpPlugin from '@prettier/plugin-php';

export default {
  plugins: [phpPlugin],
  printWidth: 120,
  tabWidth: 2,
  useTabs: false,
  semi: true,
  singleQuote: true,
  quoteProps: 'as-needed',
  trailingComma: 'all',
  bracketSpacing: true,
  bracketSameLine: false,
  arrowParens: 'always',
  proseWrap: 'preserve',
  htmlWhitespaceSensitivity: 'css',
  vueIndentScriptAndStyle: false,
  endOfLine: 'lf',
  overrides: [
    {
      files: '*.php',
      options: { tabWidth: 4, phpVersion: '8.0' },
    },
  ],
};
