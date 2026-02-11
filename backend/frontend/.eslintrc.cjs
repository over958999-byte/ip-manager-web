module.exports = {
  root: true,
  env: {
    browser: true,
    es2021: true,
    node: true
  },
  extends: [
    'eslint:recommended',
    'plugin:vue/vue3-recommended'
  ],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module'
  },
  plugins: ['vue'],
  rules: {
    // 关闭多词组件名限制
    'vue/multi-word-component-names': 'off',
    // 允许未使用变量（以 _ 开头）
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
    // Vue 相关
    'vue/no-unused-vars': 'warn',
    'vue/no-mutating-props': 'error',
    'vue/require-default-prop': 'off',
    // 代码风格
    'semi': ['warn', 'never'],
    'quotes': ['warn', 'single'],
    'comma-dangle': ['warn', 'never'],
    'no-console': process.env.NODE_ENV === 'production' ? 'warn' : 'off',
    'no-debugger': process.env.NODE_ENV === 'production' ? 'warn' : 'off'
  },
  globals: {
    defineProps: 'readonly',
    defineEmits: 'readonly',
    defineExpose: 'readonly',
    withDefaults: 'readonly'
  }
}
