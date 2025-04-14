module.exports = {
	extends: ['plugin:@wordpress/eslint-plugin/recommended', 'prettier'],
	root: true,
	env: {
		browser: true,
		es6: true,
		jquery: true,
	},
	parserOptions: {
		ecmaVersion: 2020,
		sourceType: 'module',
	},
	plugins: ['prettier'],
	rules: {
		// Add any custom rules here
		'prettier/prettier': 'error',
	},
	overrides: [
		{
			files: ['**/*.ts', '**/*.tsx'],
			parser: '@typescript-eslint/parser',
			extends: [
				'plugin:@wordpress/eslint-plugin/recommended',
				'plugin:@typescript-eslint/recommended',
				'prettier',
			],
			plugins: ['@typescript-eslint'],
			parserOptions: {
				ecmaVersion: 2020,
				sourceType: 'module',
				ecmaFeatures: {
					jsx: true,
				},
				project: './tsconfig.json',
			},
			rules: {
				// TypeScript-specific rules
				'@typescript-eslint/no-explicit-any': 'warn',
				'@typescript-eslint/explicit-function-return-type': 'off',
				'@typescript-eslint/explicit-module-boundary-types': 'off',
				// Disable rules that are handled by TypeScript
				'no-undef': 'off',
			},
		},
	],
};
