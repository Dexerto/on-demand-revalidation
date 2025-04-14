module.exports = {
	extends: ['@wordpress/stylelint-config'],
	rules: {
		// Add any custom rules here
	},
	overrides: [
		{
			files: ['**/*.scss'],
			customSyntax: 'postcss-scss',
		},
	],
};
