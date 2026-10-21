import { __ } from '@wordpress/i18n';
import { isBlobURL } from '@wordpress/blob';
import {
	BlockControls,
	InspectorControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	RichText,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextareaControl,
	ToolbarButton,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

const STEP_BLOCK = 'uat-checklist/step';
const ALLOWED_FORMATS = ['core/bold', 'core/italic', 'core/link'];
const ALLOWED_MEDIA_TYPES = ['image'];
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
 * Adds, replaces or removes the screenshot the panel shows under the step's text.
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.url        Image URL, or '' for none.
 * @param {string}   props.alt        Alternative text.
 * @param {boolean}  props.isSelected Whether the step is selected; the upload placeholder shows only then.
 * @param {Function} props.onChange   Receives the attributes to set.
 * @return {?Element} The image with its controls, the upload placeholder, or nothing.
 */
function ImageField({ url, alt, isSelected, onChange }) {
	const { createErrorNotice } = useDispatch(noticesStore);

	const onSelect = (media) => {
		// An upload reports a temporary blob: URL before the saved file's, and only the second may be stored.
		if (!media?.url || isBlobURL(media.url)) {
			return;
		}
		onChange({ imageUrl: media.url, imageAlt: media.alt ?? '' });
	};
	const onSelectURL = (value) => onChange({ imageUrl: value, imageAlt: '' });
	const onError = (message) =>
		createErrorNotice(message, { type: 'snackbar' });

	if (!url) {
		return isSelected ? (
			<MediaPlaceholder
				icon="format-image"
				labels={{
					title: __('Screenshot', 'uat-checklist'),
					instructions: __(
						'Optional. Shown under the step’s text in the panel.',
						'uat-checklist'
					),
				}}
				allowedTypes={ALLOWED_MEDIA_TYPES}
				accept="image/*"
				onSelect={onSelect}
				onSelectURL={onSelectURL}
				onError={onError}
			/>
		) : null;
	}

	return (
		<>
			<BlockControls group="other">
				<MediaReplaceFlow
					mediaURL={url}
					allowedTypes={ALLOWED_MEDIA_TYPES}
					accept="image/*"
					onSelect={onSelect}
					onSelectURL={onSelectURL}
					onError={onError}
				/>
				<ToolbarButton
					onClick={() => onChange({ imageUrl: '', imageAlt: '' })}
				>
					{__('Remove image', 'uat-checklist')}
				</ToolbarButton>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={__('Screenshot', 'uat-checklist')}>
					<TextareaControl
						label={__('Alternative text', 'uat-checklist')}
						help={__(
							'Describe what the screenshot shows, for testers who cannot see it.',
							'uat-checklist'
						)}
						value={alt}
						onChange={(value) => onChange({ imageAlt: value })}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<figure className="uat-checklist__figure">
				<img src={url} alt={alt} />
			</figure>
		</>
	);
}

/**
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {boolean}  props.isSelected    Whether the step is selected.
 * @return {Element} Editor markup for the step.
 */
export default function Edit({
	attributes,
	setAttributes,
	clientId,
	isSelected,
}) {
	const {
		stepId,
		step,
		expected,
		instructions,
		expectedItems,
		imageUrl,
		imageAlt,
	} = attributes;

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
				<ImageField
					url={imageUrl}
					alt={imageAlt}
					isSelected={isSelected}
					onChange={setAttributes}
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
