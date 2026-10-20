/*
 * Registers the testing checklist. Dynamic, but save still emits InnerBlocks.Content: it is the only path by
 * which the sections and steps reach the serialised post content.
 */

import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';

registerBlockType(metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
});
