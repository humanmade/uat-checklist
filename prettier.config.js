/*
 * Prettier configuration.
 *
 * @wordpress/prettier-config sets tabs, an 80 column width, single quotes and es5
 * trailing commas, and also `parenSpacing: true` for the `foo( bar )` style used in
 * WordPress core. @wordpress/scripts resolves `prettier` to the wp-prettier fork, which
 * is the only build of prettier that implements that option, so leaving it on would
 * reformat every call in src/ the moment the linter runs.
 *
 * It is turned off here because the source was written against plain prettier, which is
 * what the application repository this plugin was extracted from runs. Keeping the two
 * in step means a file can move between them without a formatting diff.
 */
module.exports = {
	...require('@wordpress/prettier-config'),
	parenSpacing: false,
};
