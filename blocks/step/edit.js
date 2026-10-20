import { __ } from '@wordpress/i18n';
import {
	RichText,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

const STEP_BLOCK = 'uat-checklist/step';
const ALLOWED_FORMATS = ['core/bold', 'core/italic', 'core/link'];
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * Edits the items of a list attribute in place; the spec adds and removes them.
 *
 * @param {Object}   props          Component props.
 * @param {string[]} props.items    Items.
 * @param {Function} props.onChange Receives the updated items.
 * @return {?Element} The numbered list, or nothing when there are no items.
 */
function ListField({ items, onChange }) {
	if (!items.length) {
		return null;
	}

	return (
		<ol className="uat-checklist__list">
			{items.map((item, index) => (
				<RichText
					key={index}
					tagName="li"
					value={item}
					allowedFormats={ALLOWED_FORMATS}
					onChange={(value) =>
						onChange(
							items.map((current, i) =>
								i === index ? value : current
							)
						)
					}
				/>
			))}
		</ol>
	);
}

/**
 * Renders the expected results as a list when there are several, and as one editable line otherwise.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.value         Single expected result.
 * @param {string[]} props.items         Expected results of a scenario step.
 * @param {Function} props.onChange      Receives the updated single result.
 * @param {Function} props.onChangeItems Receives the updated results.
 * @param {string}   props.placeholder   Placeholder for the single result.
 * @return {Element} The expected result.
 */
function ExpectedField({ value, items, onChange, onChangeItems, placeholder }) {
	const label = (
		<span className="uat-checklist__label">
			{__('Expected result', 'uat-checklist')}
		</span>
	);

	if (items.length) {
		return (
			<div className="uat-checklist__expected">
				{label}
				<ListField items={items} onChange={onChangeItems} />
			</div>
		);
	}

	return (
		<p className="uat-checklist__expected">
			{label}
			<RichText
				tagName="span"
				value={value}
				allowedFormats={ALLOWED_FORMATS}
				onChange={onChange}
				placeholder={placeholder}
			/>
		</p>
	);
}

/**
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {string}   props.clientId      Block client ID.
 * @return {Element} Editor markup for the step.
 */
export default function Edit({ attributes, setAttributes, clientId }) {
	const { stepId, step, expected, instructions, expectedItems } = attributes;

	// True for a new step, and for a copy whose ID an earlier step in the document already holds.
	const needsId = useSelect(
		(select) => {
			if (!UUID.test(stepId)) {
				return true;
			}

			const store = select(blockEditorStore);
			const firstHolder = store
				.getBlocksByName(STEP_BLOCK)
				.find((id) => store.getBlockAttributes(id)?.stepId === stepId);

			return firstHolder !== clientId;
		},
		[clientId, stepId]
	);

	const { __unstableMarkNextChangeAsNotPersistent } =
		useDispatch(blockEditorStore);

	useEffect(() => {
		if (needsId) {
			__unstableMarkNextChangeAsNotPersistent();
			setAttributes({ stepId: window.crypto.randomUUID() });
		}
	}, [needsId, setAttributes, __unstableMarkNextChangeAsNotPersistent]);

	const blockProps = useBlockProps({
		className: 'uat-checklist__step uat-checklist__step--editor',
	});

	return (
		<li {...blockProps}>
			<div className="uat-checklist__body">
				<RichText
					tagName="p"
					className="uat-checklist__text"
					value={step}
					allowedFormats={ALLOWED_FORMATS}
					onChange={(value) => setAttributes({ step: value })}
					placeholder={__('What the tester does', 'uat-checklist')}
				/>
				<ListField
					items={instructions}
					onChange={(value) => setAttributes({ instructions: value })}
				/>
				<ExpectedField
					value={expected}
					items={expectedItems}
					onChange={(value) => setAttributes({ expected: value })}
					onChangeItems={(value) =>
						setAttributes({ expectedItems: value })
					}
					placeholder={__('What they should see', 'uat-checklist')}
				/>
			</div>
		</li>
	);
}
