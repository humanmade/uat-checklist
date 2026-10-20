import { __ } from '@wordpress/i18n';
import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

const STEP_BLOCK = 'uat-checklist/step';

/**
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor markup for the section.
 */
export default function Edit({ attributes, setAttributes }) {
	const blockProps = useBlockProps({ className: 'uat-checklist__section' });
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'uat-checklist__steps' },
		{
			allowedBlocks: [STEP_BLOCK],
			template: [[STEP_BLOCK]],
			templateLock: false,
		}
	);

	return (
		<section {...blockProps}>
			<RichText
				tagName="h3"
				className="uat-checklist__section-title"
				value={attributes.title}
				onChange={(title) => setAttributes({ title })}
				placeholder={__(
					'Section title, for example Editor',
					'uat-checklist'
				)}
				__unstableDisableFormats
			/>
			<ol {...innerBlocksProps} />
		</section>
	);
}
