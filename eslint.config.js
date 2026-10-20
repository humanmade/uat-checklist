/*
 * ESLint flat config.
 *
 * Flat config has no .eslintignore. Ignore patterns live in the `ignores` key of the
 * first config object below, which is the only mechanism ESLint 9+ reads.
 */
const wordpress = require('@wordpress/eslint-plugin');

module.exports = [
	{
		ignores: [
			// Compiled output. Never hand-edited, never linted.
			'build/**',
			'node_modules/**',
			'vendor/**',
		],
	},
	...wordpress.configs.recommended,
	{
		languageOptions: {
			globals: {
				wp: 'readonly',
			},
		},

		/*
		 * Every `@wordpress/*` import is rewritten to a `wp` global at build time by
		 * dependency extraction, so those packages are runtime externals supplied by
		 * WordPress, not dependencies this repository installs. Listing them as core
		 * modules is what tells the import rules to treat them as
		 * resolved-but-not-from-node_modules: without it, `import/no-unresolved` flags
		 * the ones that are absent and `import/no-extraneous-dependencies` flags the
		 * ones that happen to be present as a transitive dependency of
		 * @wordpress/scripts.
		 *
		 * Add a package here when the source first imports it.
		 */
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/editor',
				'@wordpress/element',
				'@wordpress/html-entities',
				'@wordpress/i18n',
				'@wordpress/plugins',
			],
		},
	},
	{
		// A Playwright fixture hands its value on through a callback named `use`, which is not a React hook.
		files: ['tests/e2e/**/*.js'],
		rules: {
			'react-hooks/rules-of-hooks': 'off',
		},
	},
	{
		// The skill's scripts run in Node from a terminal, where printing is how they report.
		files: ['.claude/skills/**/scripts/*.mjs'],
		rules: {
			'no-console': 'off',
		},
	},
];
