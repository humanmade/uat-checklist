/*
 * Webpack configuration, extending the @wordpress/scripts default.
 *
 *   src/<name>.js             ->  build/<name>.js, .css and .asset.php
 *   blocks/<slug>/index.js    ->  build/blocks/<slug>/, beside a copy of block.json and its PHP
 *
 * inc/blocks.php registers each block from build/blocks/<slug>/, so every `file:` path in block.json resolves
 * against the compiled bundle. build/i18n-map.json maps each source file to the bundles holding it, which
 * `bin/i18n compile` hands to `wp i18n make-json --use-map` so each catalog is named after the script WordPress
 * actually loads.
 */

const fs = require('fs');
const path = require('path');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

const SRC_DIR = path.join(__dirname, 'src');
const BLOCKS_DIR = path.join(__dirname, 'blocks');
const I18N_MAP = 'i18n-map.json';
const SCRIPT_SOURCE = /\.[jt]sx?$/;

/**
 * Each .js file directly inside src/; files in subdirectories are modules, not entries.
 *
 * @return {Object<string, string>} Webpack entry map.
 */
function srcEntries() {
	return Object.fromEntries(
		fs
			.readdirSync(SRC_DIR, { withFileTypes: true })
			.filter((entry) => entry.isFile() && entry.name.endsWith('.js'))
			.map((entry) => [
				path.basename(entry.name, '.js'),
				path.join(SRC_DIR, entry.name),
			])
	);
}

/**
 * Each blocks/<slug>/index.js beside a block.json, as `blocks/<slug>/index`.
 *
 * @return {Object<string, string>} Webpack entry map.
 */
function blockEntries() {
	return Object.fromEntries(
		fs
			.readdirSync(BLOCKS_DIR, { withFileTypes: true })
			.filter((entry) => entry.isDirectory())
			.map((entry) => path.join(BLOCKS_DIR, entry.name))
			.filter(
				(dir) =>
					fs.existsSync(path.join(dir, 'block.json')) &&
					fs.existsSync(path.join(dir, 'index.js'))
			)
			.map((dir) => [
				`blocks/${path.basename(dir)}/index`,
				path.join(dir, 'index.js'),
			])
	);
}

/**
 * Emits build/i18n-map.json: each authored script, mapped to the compiled bundles it ends up in.
 *
 * @return {Object} Webpack plugin.
 */
function i18nMapPlugin() {
	return {
		apply(compiler) {
			const { Compilation, sources } = compiler.webpack;

			compiler.hooks.thisCompilation.tap(I18N_MAP, (compilation) => {
				compilation.hooks.processAssets.tap(
					{
						name: I18N_MAP,
						stage: Compilation.PROCESS_ASSETS_STAGE_SUMMARIZE,
					},
					() => {
						const map = {};

						for (const chunk of compilation.chunks) {
							const bundles = [...chunk.files]
								.filter((file) => file.endsWith('.js'))
								.map((file) => `build/${file}`);

							for (const compiled of compilation.chunkGraph.getChunkModulesIterable(
								chunk
							)) {
								// A concatenated module wraps the source modules webpack inlined into it.
								for (const inner of compiled.modules ?? [
									compiled,
								]) {
									const source = inner.resource
										? path.relative(
												__dirname,
												inner.resource.split('?')[0]
											)
										: '';

									if (
										SCRIPT_SOURCE.test(source) &&
										!source.startsWith('..') &&
										!source.includes('node_modules')
									) {
										map[source] = [
											...new Set([
												...(map[source] ?? []),
												...bundles,
											]),
										].sort();
									}
								}
							}
						}

						const sorted = Object.fromEntries(
							Object.entries(map).sort(([a], [b]) =>
								a.localeCompare(b)
							)
						);

						compilation.emitAsset(
							I18N_MAP,
							new sources.RawSource(
								JSON.stringify(sorted, null, '\t') + '\n'
							)
						);
					}
				);
			});
		},
	};
}

module.exports = {
	...defaultConfig,
	entry: { ...srcEntries(), ...blockEntries() },
	output: {
		...defaultConfig.output,
		path: path.join(__dirname, 'build'),
	},
	plugins: [
		...defaultConfig.plugins,
		// wp-scripts' own copy step looks in src/ only; the blocks live in blocks/.
		new CopyWebpackPlugin({
			patterns: [
				{
					from: 'blocks/*/{block.json,*.php}',
					context: __dirname,
					noErrorOnMissing: true,
				},
			],
		}),
		i18nMapPlugin(),
	],
};
