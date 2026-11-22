// ESLint für TypeScript und Vue: npm run lint (Teil von npm run check)
import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default tseslint.config(
  { ignores: ['public/build/**', 'vendor/**', 'node_modules/**', 'var/**', 'public/bundles/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },
  {
    languageOptions: { globals: { ...globals.browser } },
    rules: {
      // Tailwind-Klassen und Attribute sind lang – Zeilenumbrüche regelt der Mensch
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/attributes-order': 'off',
      // v-html nur für eigene, geprüfte Inhalte (jede Stelle ist kommentiert)
      'vue/no-v-html': 'warn',
      // <Icon> ist bewusst kurz – es gibt keine Kollision mit HTML-Elementen
      'vue/multi-word-component-names': ['error', { ignores: ['Icon'] }],
    },
  },
)
