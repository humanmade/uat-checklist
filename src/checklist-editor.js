/*
 * The Instructions page setting in a checklist's editor sidebar. The page it names gets the "Open the
 * instructions" link in the panel, and opens this checklist when a tester visits it.
 */

import { ComboboxControl } from '@wordpress/components';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

// Mirrored in inc/checklists.php as INSTRUCTIONS_META.
const META_KEY = '_uat_instructions_page';
const SEARCH_RESULTS = 20;

/**
 * @param {Object} page Page record from the REST API.
 * @return {{value: string, label: string}} Combobox option.
 */
function option(page) {
	return {
		value: String(page.id),
		label:
			decodeEntities(page.title?.rendered ?? '') ||
			__('(no title)', 'uat-checklist'),
	};
}

/**
 * @return {Element} The sidebar panel.
 */
function InstructionsPanel() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const [meta, setMeta] = useEntityProp('postType', postType, 'meta');
	const [search, setSearch] = useState('');
	const pageId = Number(meta?.[META_KEY] ?? 0);

	const { pages, current } = useSelect(
		(select) => ({
			pages:
				select(coreStore).getEntityRecords('postType', 'page', {
					per_page: SEARCH_RESULTS,
					search,
					_fields: 'id,title',
				}) ?? [],
			current: pageId
				? select(coreStore).getEntityRecord('postType', 'page', pageId)
				: null,
		}),
		[search, pageId]
	);

	// The chosen page stays among the options whatever the search, so the field keeps showing its title.
	const options = [
		...(current ? [option(current)] : []),
		...pages.filter((page) => page.id !== pageId).map(option),
	];

	return (
		<PluginDocumentSettingPanel
			name="uat-checklist-instructions"
			title={__('Instructions page', 'uat-checklist')}
		>
			<ComboboxControl
				label={__('Page', 'uat-checklist')}
				help={__(
					'Optional. The panel links to this page, and opens this checklist on it.',
					'uat-checklist'
				)}
				value={pageId ? String(pageId) : null}
				options={options}
				onFilterValueChange={setSearch}
				onChange={(value) =>
					setMeta({ ...meta, [META_KEY]: Number(value) || 0 })
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin('uat-checklist-instructions', { render: InstructionsPanel });
