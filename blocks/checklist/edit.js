import { __ } from '@wordpress/i18n';
import { useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';

const PARAGRAPH_BLOCK = 'core/paragraph';
const SECTION_BLOCK = 'uat-checklist/section';

/**
 * @return {Element} Editor markup for the checklist.
 */
export default function Edit() {
	const blockProps = useBlockProps({ className: 'uat-checklist' });
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'uat-checklist__sections' },
		{
			allowedBlocks: [PARAGRAPH_BLOCK, SECTION_BLOCK],
			// The paragraphs above the first section are the checklist's intro in the panel.
			template: [
				[
					PARAGRAPH_BLOCK,
					{
						placeholder: __(
							'Introduce the checklist: its tickets, and what it tests.',
							'uat-checklist'
						),
					},
				],
				[SECTION_BLOCK],
			],
			templateLock: false,
		}
	);

	return (
		<div {...blockProps}>
			<div {...innerBlocksProps} />
		</div>
	);
}
