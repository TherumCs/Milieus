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
	var allGroups = (window.MilieusBlock && window.MilieusBlock.allGroups) || [];
	var options = [{ value: '', label: __( '— Select a group —', 'milieus' ) }].concat(groups);
	var allOptions = [{ value: '', label: __( '— Select a group —', 'milieus' ) }].concat(allGroups);

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

	// ── Login block ───────────────────────────────────────────────
	blocks.registerBlockType('milieus/login', {
		title: __( 'Milieus Login Form', 'milieus' ),
		description: __( 'Login form for members of a specific group.', 'milieus' ),
		category: 'widgets',
		icon: 'lock',
		supports: { align: ['wide', 'full'] },
		attributes: {
			group: { type: 'string', default: '' },
			title: { type: 'string', default: '' }
		},
		edit: function(props) {
			var atts = props.attributes;
			var setAttr = function(k) { return function(v) { var u = {}; u[k] = v; props.setAttributes(u); }; };

			var inspector = el(InspectorControls, {},
				el(PanelBody, { title: __( 'Settings', 'milieus' ), initialOpen: true },
					el(SelectControl, {
						label: __( 'Group', 'milieus' ),
						value: atts.group,
						options: allOptions,
						onChange: setAttr('group'),
						help: allGroups.length === 0
							? __( 'No groups found. Create one under Milieus → Member Groups.', 'milieus' )
							: ''
					}),
					el(TextControl, {
						label: __( 'Override heading', 'milieus' ),
						value: atts.title,
						onChange: setAttr('title'),
						help: __( 'Leave blank to use the default.', 'milieus' )
					})
				)
			);

			if (!atts.group) {
				return el('div', {}, inspector,
					el(Placeholder, {
						icon: 'lock',
						label: __( 'Milieus Login Form', 'milieus' ),
						instructions: __( 'Pick a group in the block sidebar to render its login form.', 'milieus' )
					})
				);
			}

			return el('div', {}, inspector,
				el(ServerSideRender, {
					block: 'milieus/login',
					attributes: atts
				})
			);
		},
		save: function() { return null; }
	});

	// ── Member Status block ───────────────────────────────────────
	blocks.registerBlockType('milieus/member-status', {
		title: __( 'Milieus Member Status', 'milieus' ),
		description: __( 'Shows the current visitor their active membership groups.', 'milieus' ),
		category: 'widgets',
		icon: 'id-alt',
		supports: { align: ['wide', 'full'] },
		attributes: {
			logged_out_text: { type: 'string', default: '' },
			logged_out_href: { type: 'string', default: '' }
		},
		edit: function(props) {
			var atts = props.attributes;
			var setAttr = function(k) { return function(v) { var u = {}; u[k] = v; props.setAttributes(u); }; };

			var inspector = el(InspectorControls, {},
				el(PanelBody, { title: __( 'Logged-out fallback', 'milieus' ), initialOpen: true },
					el(TextControl, {
						label: __( 'Text when logged out', 'milieus' ),
						value: atts.logged_out_text,
						onChange: setAttr('logged_out_text'),
						help: __( 'Shown instead of status when the visitor is not logged in.', 'milieus' )
					}),
					el(TextControl, {
						label: __( 'Link URL when logged out', 'milieus' ),
						value: atts.logged_out_href,
						onChange: setAttr('logged_out_href'),
						help: __( 'Optional URL the fallback text links to (e.g. login page).', 'milieus' )
					})
				)
			);

			return el('div', {}, inspector,
				el(ServerSideRender, {
					block: 'milieus/member-status',
					attributes: atts
				})
			);
		},
		save: function() { return null; }
	});

	// ── Member Count block ────────────────────────────────────────
	blocks.registerBlockType('milieus/member-count', {
		title: __( 'Milieus Member Count', 'milieus' ),
		description: __( 'Displays the number of active members in a group.', 'milieus' ),
		category: 'widgets',
		icon: 'chart-bar',
		supports: { align: true },
		attributes: {
			group:  { type: 'string', default: '' },
			format: { type: 'string', default: 'pretty' }
		},
		edit: function(props) {
			var atts = props.attributes;
			var setAttr = function(k) { return function(v) { var u = {}; u[k] = v; props.setAttributes(u); }; };

			var inspector = el(InspectorControls, {},
				el(PanelBody, { title: __( 'Settings', 'milieus' ), initialOpen: true },
					el(SelectControl, {
						label: __( 'Group', 'milieus' ),
						value: atts.group,
						options: allOptions,
						onChange: setAttr('group'),
						help: allGroups.length === 0
							? __( 'No groups found. Create one under Milieus → Member Groups.', 'milieus' )
							: ''
					}),
					el(SelectControl, {
						label: __( 'Format', 'milieus' ),
						value: atts.format,
						options: [
							{ value: 'pretty', label: __( 'Pretty (e.g. "1,234")', 'milieus' ) },
							{ value: 'raw',    label: __( 'Raw number', 'milieus' ) }
						],
						onChange: setAttr('format')
					})
				)
			);

			if (!atts.group) {
				return el('div', {}, inspector,
					el(Placeholder, {
						icon: 'chart-bar',
						label: __( 'Milieus Member Count', 'milieus' ),
						instructions: __( 'Pick a group in the block sidebar to show its member count.', 'milieus' )
					})
				);
			}

			return el('div', {}, inspector,
				el(ServerSideRender, {
					block: 'milieus/member-count',
					attributes: atts
				})
			);
		},
		save: function() { return null; }
	});
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n);
