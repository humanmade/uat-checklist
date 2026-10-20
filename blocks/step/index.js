/*
 * Registers the checklist step. Styled from blocks/checklist/style.scss, the checklist it sits in.
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType(metadata.name, {
	edit: Edit,
	save: () => null,
});
