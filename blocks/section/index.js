/*
 * Registers the checklist section. Styled from blocks/checklist/style.scss, the parent it is locked to.
 */

import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

import metadata from './block.json';
import Edit from './edit';

registerBlockType(metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
});
