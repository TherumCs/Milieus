/* Milieus by Therum — Registration block (editor-side).
   Plain ES5 so no build step. Uses ServerSideRender for the preview so
   what shows in the editor is identical to what the visitor sees. */
(function(blocks, element, components, blockEditor, serverSideRender, i18n) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var SelectControl   = components.SelectControl;
	var TextControl     = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var PanelBody       = components.PanelBody;
	var Placeholder     = components.Placeholder;
	var InspectorControls = blockEditor.InspectorControls;
	var ServerSideRender = serverSideRender.default || serverSideRender;

	var groups = (window.MilieusBlock && window.MilieusBlock.groups) || [];
	var options = [{ value: '', label: __( '— Select a group —', 'milieus' ) }].concat(groups);

	blocks.registerBlockType('milieus/register', {
		title: __( 'Milieus Registration Form', 'milieus' ),
		description: __( 'Inline sign-up form that auto-assigns visitors to a member group.', 'milieus' ),
		category: 'widgets',
		icon: 'groups',
		supports: { align: ['wide', 'full'] },
		attributes: {
			group: { type: 'string', default: '' },
			title: { type: 'string', default: '' },
			lede:  { type: 'string', default: '' }
		},
		edit: function(props) {
			var atts = props.attributes;
			var setAttr = function(k) { return function(v) { var u = {}; u[k] = v; props.setAttributes(u); }; };

			var inspector = el(InspectorControls, {},
				el(PanelBody, { title: __( 'Group', 'milieus' ), initialOpen: true },
					el(SelectControl, {
						label: __( 'Group', 'milieus' ),
						value: atts.group,
						options: options,
						onChange: setAttr('group'),
						help: groups.length === 0
							? __( 'No groups have an enabled registration link. Create one under Milieus → Member Groups.', 'milieus' )
							: ''
					}),
					el(TextControl, {
						label: __( 'Override heading', 'milieus' ),
						value: atts.title,
						onChange: setAttr('title'),
						help: __( 'Leave blank to use the group\'s default.', 'milieus' )
					}),
					el(TextareaControl, {
						label: __( 'Override welcome message', 'milieus' ),
						value: atts.lede,
						onChange: setAttr('lede'),
						help: __( 'Leave blank to use the group\'s default.', 'milieus' )
					})
				)
			);

			if (!atts.group) {
				return el('div', {}, inspector,
					el(Placeholder, {
						icon: 'groups',
						label: __( 'Milieus Registration Form', 'milieus' ),
						instructions: __( 'Pick a group in the block sidebar to render its registration form.', 'milieus' )
					})
				);
			}

			return el('div', {}, inspector,
				el(ServerSideRender, {
					block: 'milieus/register',
					attributes: atts
				})
			);
		},
		save: function() { return null; } // dynamic block
	});
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n);
