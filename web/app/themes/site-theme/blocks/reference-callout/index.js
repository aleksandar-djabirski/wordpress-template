/**
 * Editor script for `agency/reference-callout` — the theme's reference
 * block for "standard editor controls suffice" (see README.md). The block
 * is dynamic (server-rendered by render.php), so `save` returns `null` and
 * WordPress stores only the attributes in post content.
 *
 * The testimonial shown in the editor is REPRESENTATIVE placeholder content.
 * The real testimonial comes from `SiteCore\Contracts\Testimonials::latest()`
 * at render time (see `render.php`) and is frontend-only; `ServerSideRender`
 * is deliberately not used, because a client-side preview is both faster and
 * editable.
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
				{ showTestimonial && (
					<blockquote className="reference-callout__testimonial reference-callout__testimonial--preview">
						<p>
							{ __(
								'This starter cut our build time in half and the client still edits everything themselves.',
								'site-theme'
							) }
						</p>
						<cite>
							{ __(
								'Representative preview — the live testimonial is loaded when the page is viewed.',
								'site-theme'
							) }
						</cite>
					</blockquote>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata, {
	edit: Edit,
	save: () => null,
} );
