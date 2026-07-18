/**
 * Editor script for `agency/reference-callout` — the theme's reference
 * block for "standard editor controls suffice" (see README.md). The block
 * is dynamic (server-rendered by render.php), so `save` returns `null` and
 * WordPress stores only the attributes in post content.
 *
 * Built by `npm run build` (@wordpress/scripts) into ./build/index.js,
 * which block.json's `editorScript` references via `file:./build/index.js`.
 */

import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	const { heading, content, showTestimonial } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Reference Callout', 'site-theme' ) }>
					<ToggleControl
						label={ __( 'Show latest testimonial', 'site-theme' ) }
						checked={ Boolean( showTestimonial ) }
						onChange={ ( value ) =>
							setAttributes( { showTestimonial: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<RichText
					tagName="h2"
					className="reference-callout__heading"
					value={ heading }
					onChange={ ( value ) =>
						setAttributes( { heading: value } )
					}
					placeholder={ __( 'Heading', 'site-theme' ) }
				/>
				<RichText
					tagName="p"
					className="reference-callout__content"
					value={ content }
					onChange={ ( value ) =>
						setAttributes( { content: value } )
					}
					placeholder={ __( 'Body copy', 'site-theme' ) }
				/>
			</div>
		</>
	);
}

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
