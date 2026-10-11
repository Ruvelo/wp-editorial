/**
 * Ruvelo Editorial: the Review panel in the block editor.
 *
 * Plain JavaScript on WordPress's own globals (wp.element, wp.components,
 * wp.data…), no build step. It shows the post's review state and lets
 * people ask for a review, approve or request changes; it explains a
 * publish gate in the pre-publish panel and holds the button while the
 * gate is closed. The server enforces the gate either way.
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.plugins || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useCallback = wp.element.useCallback;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var editorPlugins = wp.editor && wp.editor.PluginDocumentSettingPanel ? wp.editor : wp.editPost;
	var PluginDocumentSettingPanel = editorPlugins.PluginDocumentSettingPanel;
	var PluginPrePublishPanel = editorPlugins.PluginPrePublishPanel;
	var C = wp.components;
	var DOMAIN = 'ruvelo-editorial';
	var LOCK = 'ruvelo-editorial-gate';

	// The latest review state, shared with the save guard below.
	var latest = null;

	function api( path, data ) {
		return wp.apiFetch( {
			path: '/' + config.namespace + path,
			method: data ? 'POST' : 'GET',
			data: data,
		} );
	}

	function notice( type, message ) {
		wp.data.dispatch( 'core/notices' ).createNotice( type, message, {
			type: 'snackbar',
			isDismissible: true,
			id: 'ruvelo-editorial-notice',
		} );
	}

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'That didn’t work. Try again.', DOMAIN );
	}

	function decisionLabel( decision ) {
		switch ( decision ) {
			case 'approved':
				return __( 'Approved', DOMAIN );
			case 'changes_requested':
				return __( 'Requested changes', DOMAIN );
			case 'pending':
				return __( 'Hasn’t reviewed yet', DOMAIN );
			default:
				return __( 'Not asked this round', DOMAIN );
		}
	}

	function formatDate( ymd ) {
		if ( ! ymd ) {
			return '';
		}
		var date = new Date( ymd + 'T12:00:00' );
		return date.toLocaleDateString( undefined, { day: 'numeric', month: 'short', year: 'numeric' } );
	}

	function openNotes() {
		var iface = wp.data.dispatch( 'core/interface' );
		if ( iface && iface.enableComplementaryArea ) {
			iface.enableComplementaryArea( 'core', 'edit-post/collab-history-sidebar' );
		}
	}

	/**
	 * The review state of the current post, refreshed after every save.
	 */
	function useReview( postId, postStatus ) {
		var state = useState( null );
		var review = state[ 0 ];
		var setReview = state[ 1 ];
		var isSaving = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return editor.isSavingPost() && ! editor.isAutosavingPost();
		}, [] );
		var wasSaving = useRef( false );

		var refresh = useCallback(
			function () {
				if ( ! postId || postStatus === 'auto-draft' ) {
					return Promise.resolve();
				}
				return api( '/posts/' + postId )
					.then( function ( data ) {
						latest = data;
						setReview( data );
					} )
					.catch( function () {} );
			},
			[ postId, postStatus ]
		);

		useEffect( function () {
			refresh();
		}, [ refresh ] );

		useEffect(
			function () {
				if ( wasSaving.current && ! isSaving ) {
					refresh();
				}
				wasSaving.current = isSaving;
			},
			[ isSaving ]
		);

		return [ review, setReview, refresh ];
	}

	function Badge( props ) {
		return el( 'span', { className: 'ruvelo-ed-badge ruvelo-ed-tone-' + props.tone }, props.label );
	}

	function Person( props ) {
		var person = props.person;
		return el(
			'li',
			{ className: 'ruvelo-ed-person-row' },
			el(
				'span',
				{ className: 'ruvelo-ed-person is-' + String( person.decision || '' ).replace( '_', '-' ), 'aria-hidden': 'true' },
				person.initials
			),
			el(
				'span',
				{ className: 'ruvelo-ed-person-text' },
				el( 'strong', null, person.name ),
				el( 'small', null, decisionLabel( person.decision ) )
			)
		);
	}

	function Meter( props ) {
		var pct = Math.min( 100, Math.round( ( props.count / Math.max( 1, props.required ) ) * 100 ) );
		return el(
			'div',
			{ className: 'ruvelo-ed-approvals' },
			el(
				'span',
				{ className: 'ruvelo-ed-approvals-text' },
				sprintf(
					/* translators: 1: approvals so far, 2: approvals needed */
					__( '%1$d of %2$d approvals', DOMAIN ),
					props.count,
					props.required
				)
			),
			el( 'span', { className: 'ruvelo-ed-meter', 'aria-hidden': 'true' }, el( 'span', { style: { width: pct + '%' } } ) )
		);
	}

	/**
	 * Approve or request changes, with an optional message that can become
	 * a core note on the selected block.
	 */
	function DecisionForm( props ) {
		var review = props.review;
		var kind = props.kind;
		var messageState = useState( '' );
		var noteState = useState( false );
		var busyState = useState( false );
		var ref = useRef();
		var block = useSelect( function ( select ) {
			var be = select( 'core/block-editor' );
			var clientId = be.getSelectedBlockClientId();
			return clientId ? { clientId: clientId, attributes: be.getBlockAttributes( clientId ), name: be.getBlockName( clientId ) } : null;
		}, [] );
		var existing = block && block.attributes && block.attributes.metadata ? block.attributes.metadata.noteId : null;
		var canAnchor = !! block && config.notes && ( config.multipleNotes || ! existing );

		useEffect( function () {
			if ( ref.current ) {
				ref.current.focus();
			}
		}, [] );

		function addNote( message ) {
			var core = wp.data.dispatch( 'core' );
			return core
				.saveEntityRecord(
					'root',
					'comment',
					{ post: review.post_id, content: message, status: 'hold', type: 'note', parent: 0 },
					{ throwOnError: true }
				)
				.then( function ( note ) {
					var meta = Object.assign( {}, ( block.attributes && block.attributes.metadata ) || {} );
					if ( meta.noteId ) {
						var ids = Array.isArray( meta.noteId ) ? meta.noteId.slice() : [ meta.noteId ];
						ids.push( note.id );
						meta.noteId = ids;
					} else {
						meta.noteId = config.multipleNotes ? [ note.id ] : note.id;
					}
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { metadata: meta } );
					return note.id;
				} );
		}

		function submit( event ) {
			event.preventDefault();
			var message = messageState[ 0 ].trim();
			if ( kind === 'changes' && noteState[ 0 ] && ! message ) {
				notice( 'error', __( 'Write a message to leave it as a note.', DOMAIN ) );
				return;
			}
			busyState[ 1 ]( true );
			var withNote = noteState[ 0 ] && canAnchor && message ? addNote( message ) : Promise.resolve( 0 );
			withNote
				.then( function ( noteId ) {
					return api( '/posts/' + review.post_id + ( kind === 'approve' ? '/approve' : '/request-changes' ), {
						message: message,
						note_id: noteId || 0,
					} ).then( function ( data ) {
						return { data: data, noteId: noteId };
					} );
				} )
				.then( function ( result ) {
					latest = result.data;
					props.onDone( result.data );
					var text = kind === 'approve' ? __( 'Approved. The author has been told.', DOMAIN ) : __( 'Changes requested. The author has been told.', DOMAIN );
					if ( result.noteId ) {
						text += ' ' + __( 'Save the post to keep the note on its block.', DOMAIN );
					}
					notice( 'success', text );
				} )
				.catch( function ( error ) {
					busyState[ 1 ]( false );
					notice( 'error', errorMessage( error ) );
				} );
		}

		var children = [
			el( C.TextareaControl, {
				key: 'message',
				ref: ref,
				label: kind === 'approve' ? __( 'Message for the author (optional)', DOMAIN ) : __( 'What needs to change?', DOMAIN ),
				value: messageState[ 0 ],
				onChange: messageState[ 1 ],
				rows: 4,
				__nextHasNoMarginBottom: true,
			} ),
		];
		if ( config.notes ) {
			children.push(
				el( C.CheckboxControl, {
					key: 'note',
					label: __( 'Also leave it as a note on the selected block', DOMAIN ),
					help: ! block
						? __( 'Select a block first.', DOMAIN )
						: ! canAnchor
						? __( 'This block already has a note.', DOMAIN )
						: undefined,
					checked: noteState[ 0 ] && canAnchor,
					disabled: ! canAnchor,
					onChange: noteState[ 1 ],
					__nextHasNoMarginBottom: true,
				} )
			);
		}
		children.push(
			el(
				'div',
				{ key: 'buttons', className: 'ruvelo-ed-buttons' },
				el(
					C.Button,
					{ variant: 'primary', type: 'submit', isBusy: busyState[ 0 ], disabled: busyState[ 0 ] },
					kind === 'approve' ? __( 'Approve', DOMAIN ) : __( 'Send back to the author', DOMAIN )
				),
				el( C.Button, { variant: 'tertiary', onClick: props.onCancel }, __( 'Cancel', DOMAIN ) )
			)
		);

		return el( 'form', { className: 'ruvelo-ed-form', onSubmit: submit }, children );
	}

	/**
	 * Ask for a review, ask again, or change who reviews.
	 */
	function RequestForm( props ) {
		var review = props.review;
		var peopleState = useState( null );
		var current = ( review.reviewers || [] ).map( function ( r ) {
			return r.id;
		} );
		var pickedState = useState( current );
		var dueState = useState( review.due || '' );
		var messageState = useState( '' );
		var busyState = useState( false );

		useEffect( function () {
			api( '/reviewers?post=' + review.post_id )
				.then( peopleState[ 1 ] )
				.catch( function () {
					peopleState[ 1 ]( [] );
				} );
		}, [] );

		function toggle( id, on ) {
			var next = pickedState[ 0 ].filter( function ( x ) {
				return x !== id;
			} );
			if ( on ) {
				next.push( id );
			}
			pickedState[ 1 ]( next );
		}

		function submit( event ) {
			event.preventDefault();
			busyState[ 1 ]( true );
			api( '/posts/' + review.post_id + '/request', {
				reviewers: pickedState[ 0 ],
				due: dueState[ 0 ],
				message: messageState[ 0 ],
			} )
				.then( function ( data ) {
					latest = data;
					props.onDone( data );
					notice( 'success', review.state === 'in_review' ? __( 'Reviewers updated.', DOMAIN ) : __( 'Review requested. The reviewers have been told.', DOMAIN ) );
				} )
				.catch( function ( error ) {
					busyState[ 1 ]( false );
					notice( 'error', errorMessage( error ) );
				} );
		}

		var people = peopleState[ 0 ];
		var list;
		if ( people === null ) {
			list = el( C.Spinner, null );
		} else if ( ! people.length ) {
			list = el( 'p', null, __( 'Nobody can review this post yet. Editors and administrators can, by default.', DOMAIN ) );
		} else {
			list = el(
				'fieldset',
				{ className: 'ruvelo-ed-pick' },
				el( 'legend', null, __( 'Reviewers', DOMAIN ) ),
				people.map( function ( person ) {
					return el( C.CheckboxControl, {
						key: person.id,
						label: person.name,
						checked: pickedState[ 0 ].indexOf( person.id ) !== -1,
						onChange: function ( on ) {
							toggle( person.id, on );
						},
						__nextHasNoMarginBottom: true,
					} );
				} )
			);
		}

		var required = review.approvals.required;
		return el(
			'form',
			{ className: 'ruvelo-ed-form', onSubmit: submit },
			list,
			required > 1
				? el(
						'p',
						{ className: 'ruvelo-ed-help' },
						sprintf(
							/* translators: %d: approvals needed */
							_n( 'It needs %d approval.', 'It needs %d approvals.', required, DOMAIN ),
							required
						)
				  )
				: null,
			el( C.TextControl, {
				label: __( 'Due (optional)', DOMAIN ),
				type: 'date',
				value: dueState[ 0 ],
				onChange: dueState[ 1 ],
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			el( C.TextareaControl, {
				label: __( 'Message for the reviewers (optional)', DOMAIN ),
				value: messageState[ 0 ],
				onChange: messageState[ 1 ],
				rows: 3,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				'div',
				{ className: 'ruvelo-ed-buttons' },
				el(
					C.Button,
					{ variant: 'primary', type: 'submit', isBusy: busyState[ 0 ], disabled: busyState[ 0 ] || ! pickedState[ 0 ].length },
					review.state === 'in_review' ? __( 'Save reviewers', DOMAIN ) : __( 'Ask for a review', DOMAIN )
				),
				el( C.Button, { variant: 'tertiary', onClick: props.onCancel }, __( 'Cancel', DOMAIN ) )
			)
		);
	}

	function ReviewPanel() {
		var post = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return { id: editor.getCurrentPostId(), status: editor.getCurrentPostAttribute( 'status' ) };
		}, [] );
		var hook = useReview( post.id, post.status );
		var review = hook[ 0 ];
		var setReview = hook[ 1 ];
		var modeState = useState( '' );
		var mode = modeState[ 0 ];
		var setMode = modeState[ 1 ];

		// Open the panel the first time someone meets it; after that it
		// remembers whatever they chose, like every other panel.
		useEffect( function () {
			var name = 'ruvelo-editorial/ruvelo-editorial-review';
			try {
				if ( window.localStorage.getItem( 'ruveloEditorialPanelSeen' ) ) {
					return;
				}
				window.localStorage.setItem( 'ruveloEditorialPanelSeen', '1' );
			} catch ( e ) {
				return;
			}
			if ( ! wp.data.select( 'core/editor' ).isEditorPanelOpened( name ) ) {
				wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( name );
			}
		}, [] );

		if ( post.status === 'auto-draft' ) {
			return el(
				PluginDocumentSettingPanel,
				{ name: 'ruvelo-editorial-review', title: __( 'Review', DOMAIN ), className: 'ruvelo-ed-panel' },
				el( 'p', null, __( 'Save the draft to ask for a review.', DOMAIN ) )
			);
		}
		if ( ! review ) {
			return el(
				PluginDocumentSettingPanel,
				{ name: 'ruvelo-editorial-review', title: __( 'Review', DOMAIN ), className: 'ruvelo-ed-panel' },
				el( C.Spinner, null )
			);
		}

		function done( data ) {
			setReview( data );
			setMode( '' );
		}

		function withdraw() {
			api( '/posts/' + review.post_id + '/withdraw', { message: '' } )
				.then( function ( data ) {
					latest = data;
					setReview( data );
					notice( 'success', __( 'Taken out of review.', DOMAIN ) );
				} )
				.catch( function ( error ) {
					notice( 'error', errorMessage( error ) );
				} );
		}

		var body = [];
		body.push(
			el(
				'div',
				{ key: 'state', className: 'ruvelo-ed-state', 'aria-live': 'polite' },
				el( Badge, { tone: review.tone, label: review.state_label } ),
				review.state !== 'draft' ? el( Meter, { count: review.approvals.count, required: review.approvals.required } ) : null
			)
		);

		if ( review.changes ) {
			body.push(
				el(
					'div',
					{ key: 'changes', className: 'ruvelo-ed-callout ruvelo-ed-callout--warning' },
					el(
						'strong',
						null,
						sprintf(
							/* translators: %s: reviewer */
							__( '%s requested changes', DOMAIN ),
							review.changes.user.name
						)
					),
					review.changes.message ? el( 'p', null, review.changes.message ) : null
				)
			);
		}

		if ( review.reviewers.length ) {
			body.push(
				el(
					'ul',
					{ key: 'people', className: 'ruvelo-ed-people-list', 'aria-label': __( 'Reviewers', DOMAIN ) },
					review.reviewers.map( function ( person ) {
						return el( Person, { key: person.id, person: person } );
					} )
				)
			);
		}

		var facts = [];
		if ( review.due ) {
			facts.push(
				el(
					'li',
					{ key: 'due', className: review.overdue ? 'is-overdue' : '' },
					review.overdue
						? sprintf(
								/* translators: %s: date */
								__( 'Overdue: was due %s', DOMAIN ),
								formatDate( review.due )
						  )
						: sprintf(
								/* translators: %s: date */
								__( 'Due %s', DOMAIN ),
								formatDate( review.due )
						  )
				)
			);
		}
		if ( review.notes.supported ) {
			facts.push(
				el(
					'li',
					{ key: 'notes' },
					el(
						C.Button,
						{ variant: 'link', onClick: openNotes },
						review.notes.open
							? sprintf(
									/* translators: %d: open notes */
									_n( '%d open note', '%d open notes', review.notes.open, DOMAIN ),
									review.notes.open
							  )
							: __( 'No open notes', DOMAIN )
					)
				)
			);
		}
		if ( review.gate.enabled && [ 'publish', 'future' ].indexOf( review.post_status ) === -1 ) {
			facts.push(
				el(
					'li',
					{ key: 'gate', className: review.gate.passes ? 'is-ok' : 'is-held' },
					review.gate.passes ? __( 'Ready to publish.', DOMAIN ) : review.gate.reasons.join( ' ' )
				)
			);
		}
		if ( facts.length ) {
			body.push( el( 'ul', { key: 'facts', className: 'ruvelo-ed-facts' }, facts ) );
		}

		if ( mode === 'approve' || mode === 'changes' ) {
			body.push(
				el( DecisionForm, {
					key: 'decide',
					kind: mode,
					review: review,
					onDone: done,
					onCancel: function () {
						setMode( '' );
					},
				} )
			);
		} else if ( mode === 'request' ) {
			body.push(
				el( RequestForm, {
					key: 'request',
					review: review,
					onDone: done,
					onCancel: function () {
						setMode( '' );
					},
				} )
			);
		} else {
			var buttons = [];
			if ( review.can.decide ) {
				if ( review.can.approve ) {
					buttons.push(
						el( C.Button, { key: 'approve', variant: 'primary', onClick: function () { setMode( 'approve' ); } }, __( 'Approve', DOMAIN ) )
					);
				}
				buttons.push(
					el( C.Button, { key: 'changes', variant: 'secondary', onClick: function () { setMode( 'changes' ); } }, __( 'Request changes', DOMAIN ) )
				);
			}
			if ( review.can.request ) {
				var label =
					review.state === 'in_review'
						? __( 'Change reviewers', DOMAIN )
						: review.state === 'draft'
						? __( 'Ask for a review', DOMAIN )
						: __( 'Ask for a review again', DOMAIN );
				buttons.push(
					el(
						C.Button,
						{
							key: 'request',
							variant: review.state === 'draft' || review.state === 'changes_requested' ? 'primary' : 'secondary',
							onClick: function () {
								setMode( 'request' );
							},
						},
						label
					)
				);
			}
			if ( buttons.length ) {
				body.push( el( 'div', { key: 'actions', className: 'ruvelo-ed-buttons' }, buttons ) );
			}
			var links = [ el( C.Button, { key: 'history', variant: 'link', href: config.reviewUrl + review.post_id }, __( 'Review history', DOMAIN ) ) ];
			if ( review.can.withdraw ) {
				links.push( el( C.Button, { key: 'withdraw', variant: 'link', isDestructive: true, onClick: withdraw }, __( 'Take out of review', DOMAIN ) ) );
			}
			body.push( el( 'div', { key: 'links', className: 'ruvelo-ed-links' }, links ) );
		}

		return el(
			PluginDocumentSettingPanel,
			{ name: 'ruvelo-editorial-review', title: __( 'Review', DOMAIN ), className: 'ruvelo-ed-panel' },
			body
		);
	}

	/**
	 * In the pre-publish panel: why publishing waits, and (for people who
	 * may) publishing anyway with a reason. Holds the Publish button while
	 * the gate is closed.
	 */
	function GatePanel() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				status: editor.getCurrentPostAttribute( 'status' ),
				open: editor.isPublishSidebarOpened(),
			};
		}, [] );
		var reviewState = useState( latest );
		var reasonState = useState( '' );
		var busyState = useState( false );
		var review = reviewState[ 0 ];

		useEffect(
			function () {
				if ( data.open && data.id && data.status !== 'auto-draft' ) {
					api( '/posts/' + data.id )
						.then( function ( fresh ) {
							latest = fresh;
							reviewState[ 1 ]( fresh );
						} )
						.catch( function () {} );
				}
			},
			[ data.open ]
		);

		var held = !! ( review && review.gate.enabled && ! review.gate.passes );
		useEffect(
			function () {
				var editor = wp.data.dispatch( 'core/editor' );
				if ( held && data.open ) {
					editor.lockPostSaving( LOCK );
				} else {
					editor.unlockPostSaving( LOCK );
				}
				return function () {
					editor.unlockPostSaving( LOCK );
				};
			},
			[ held, data.open ]
		);

		if ( ! review || ! review.gate.enabled ) {
			return null;
		}

		function allow( event ) {
			event.preventDefault();
			busyState[ 1 ]( true );
			api( '/posts/' + review.post_id + '/override', { reason: reasonState[ 0 ] } )
				.then( function ( fresh ) {
					latest = fresh;
					reviewState[ 1 ]( fresh );
					busyState[ 1 ]( false );
					wp.a11y && wp.a11y.speak && wp.a11y.speak( __( 'You can publish now. Your reason will be logged.', DOMAIN ) );
				} )
				.catch( function ( error ) {
					busyState[ 1 ]( false );
					notice( 'error', errorMessage( error ) );
				} );
		}

		var content;
		if ( review.gate.passes ) {
			content = el(
				'p',
				{ className: 'ruvelo-ed-gate is-ok' },
				review.gate.override
					? __( 'Publishing without approval. Your reason will be logged and sent to the reviewers.', DOMAIN )
					: sprintf(
							/* translators: 1: approvals so far, 2: approvals needed */
							__( 'Approved: %1$d of %2$d approvals.', DOMAIN ),
							review.approvals.count,
							review.approvals.required
					  )
			);
		} else {
			content = el(
				Fragment,
				null,
				el( 'p', { className: 'ruvelo-ed-gate is-held', role: 'alert' }, review.gate.reasons.join( ' ' ) ),
				el(
					'p',
					null,
					review.state === 'draft'
						? __( 'Ask for a review from the Review panel in the sidebar.', DOMAIN )
						: __( 'Publishing opens once the reviewers approve.', DOMAIN )
				),
				review.gate.can_override
					? el(
							'form',
							{ className: 'ruvelo-ed-form', onSubmit: allow },
							el( C.TextareaControl, {
								label: __( 'Publish anyway: why can’t this wait?', DOMAIN ),
								help: __( 'Logged in the review history and sent to the reviewers.', DOMAIN ),
								value: reasonState[ 0 ],
								onChange: reasonState[ 1 ],
								rows: 3,
								__nextHasNoMarginBottom: true,
							} ),
							el(
								C.Button,
								{ variant: 'secondary', isDestructive: true, type: 'submit', isBusy: busyState[ 0 ], disabled: busyState[ 0 ] || ! reasonState[ 0 ].trim() },
								__( 'Allow publishing once', DOMAIN )
							)
					  )
					: null
			);
		}

		return el(
			PluginPrePublishPanel,
			{ title: __( 'Review', DOMAIN ), initialOpen: true, className: 'ruvelo-ed-panel' },
			content
		);
	}

	/*
	 * Belt and braces for people who switched the pre-publish checks off:
	 * stop a publish or schedule the gate would refuse anyway, with the
	 * reason, and put the status back so "Save" saves a draft.
	 */
	wp.hooks.addFilter( 'editor.preSavePost', 'ruvelo-editorial/gate', function ( edits ) {
		var editor = wp.data.select( 'core/editor' );
		var current = editor.getCurrentPostAttribute( 'status' );
		var publishing = edits && ( edits.status === 'publish' || edits.status === 'future' );
		if ( ! publishing || current === 'publish' || current === 'future' || ! latest || latest.post_id !== editor.getCurrentPostId() ) {
			return edits;
		}
		if ( latest.gate.enabled && ! latest.gate.passes ) {
			wp.data.dispatch( 'core/editor' ).editPost( { status: current } );
			throw new Error( latest.gate.reasons.join( ' ' ) );
		}
		return edits;
	} );

	wp.plugins.registerPlugin( 'ruvelo-editorial', {
		icon: 'yes-alt',
		render: function () {
			return el( Fragment, null, el( ReviewPanel, null ), el( GatePanel, null ) );
		},
	} );
} )( window.wp, window.ruveloEditorial );
