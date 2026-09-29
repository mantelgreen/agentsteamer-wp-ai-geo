( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var useEntityProp = wp.coreData && wp.coreData.useEntityProp;
	var registerPlugin = wp.plugins.registerPlugin;
	var __ = wp.i18n.__;

	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var PluginSidebarMoreMenuItem =
		( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );

	if ( ! PluginSidebar ) {
		return;
	}

	if ( wp.apiFetch && wp.apiFetch.createNonceMiddleware && window.AgentSteamerAIEditor && window.AgentSteamerAIEditor.nonce ) {
		wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( window.AgentSteamerAIEditor.nonce ) );
	}

	var c = wp.components;
	var TextControl = c.TextControl;	var TextareaControl = c.TextareaControl;
	var ToggleControl = c.ToggleControl;
	var Button = c.Button;
	var PanelBody = c.PanelBody;
	var Spinner = c.Spinner;
	var Notice = c.Notice;

	var META = {
		title: '_asi_title',
		description: '_asi_description',
		canonical: '_asi_canonical',
		noindex: '_asi_noindex',
		keyword: '_asi_focus_keyword',
		schema_faq: '_asi_schema_faq',
		schema_howto: '_asi_schema_howto',
		schema_howto_name: '_asi_schema_howto_name',
		schema_custom: '_asi_schema_custom',
		schema_disabled: '_asi_schema_disabled',
		speakable: '_asi_speakable'
	};

	var SIDEBAR = 'agentsteamer-ai-sidebar';

	function len( s ) {
		return s ? Array.from( String( s ).trim() ).length : 0;
	}

	function stripTags( html ) {
		return String( html || '' )
			.replace( /<[^>]+>/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function analyze( meta, content, postTitle ) {
		var checks = [];
		var html = content || '';
		var text = stripTags( html );
		var chars = len( text );
		var kw = String( meta[ META.keyword ] || '' ).trim().toLowerCase();
		var seoTitle = meta[ META.title ] || '';
		var desc = meta[ META.description ] || '';

		function add( status, label ) {
			checks.push( { status: status, label: label } );
		}

		var tLen = len( seoTitle || postTitle );
		if ( tLen === 0 ) {
			add( 'bad', __( 'SEO 标题为空', 'agentsteamer-ai' ) );
		} else if ( tLen < 20 ) {
			add( 'warn', __( 'SEO 标题偏短，建议 30–60 字符', 'agentsteamer-ai' ) );
		} else if ( tLen > 60 ) {
			add( 'warn', __( 'SEO 标题偏长，建议不超过 60 字符', 'agentsteamer-ai' ) );
		} else {
			add( 'good', __( 'SEO 标题长度合适', 'agentsteamer-ai' ) );
		}

		var dLen = len( desc );
		if ( dLen === 0 ) {
			add( 'bad', __( 'Meta 描述为空', 'agentsteamer-ai' ) );
		} else if ( dLen < 60 ) {
			add( 'warn', __( 'Meta 描述偏短，建议 120–160 字符', 'agentsteamer-ai' ) );
		} else if ( dLen > 160 ) {
			add( 'warn', __( 'Meta 描述偏长，建议不超过 160 字符', 'agentsteamer-ai' ) );
		} else {
			add( 'good', __( 'Meta 描述长度合适', 'agentsteamer-ai' ) );
		}

		if ( kw ) {
			add(
				seoTitle.toLowerCase().indexOf( kw ) !== -1 ? 'good' : 'bad',
				seoTitle.toLowerCase().indexOf( kw ) !== -1
					? __( '关键词出现在 SEO 标题中', 'agentsteamer-ai' )
					: __( '关键词未出现在 SEO 标题中', 'agentsteamer-ai' )
			);
			add(
				desc.toLowerCase().indexOf( kw ) !== -1 ? 'good' : 'warn',
				desc.toLowerCase().indexOf( kw ) !== -1
					? __( '关键词出现在 Meta 描述中', 'agentsteamer-ai' )
					: __( '关键词未出现在 Meta 描述中', 'agentsteamer-ai' )
			);
			add(
				html.toLowerCase().indexOf( kw ) !== -1 ? 'good' : 'warn',
				html.toLowerCase().indexOf( kw ) !== -1
					? __( '关键词出现在正文中', 'agentsteamer-ai' )
					: __( '关键词未出现在正文中', 'agentsteamer-ai' )
			);
		} else {
			add( 'warn', __( '尚未设置焦点关键词', 'agentsteamer-ai' ) );
		}

		if ( chars < 300 ) {
			add( 'bad', __( '正文偏短，建议不少于 300 字', 'agentsteamer-ai' ) );
		} else if ( chars < 600 ) {
			add( 'warn', __( '正文内容一般，建议不少于 600 字', 'agentsteamer-ai' ) );
		} else {
			add( 'good', __( '正文长度充足', 'agentsteamer-ai' ) );
		}

		add(
			/<h[23][\s>]/i.test( html ) ? 'good' : 'warn',
			/<h[23][\s>]/i.test( html )
				? __( '包含 H2/H3 小标题结构', 'agentsteamer-ai' )
				: __( '缺少 H2/H3 小标题，影响内容可提取性', 'agentsteamer-ai' )
		);

		add(
			/<(ul|ol)[\s>]/i.test( html ) ? 'good' : 'warn',
			/<(ul|ol)[\s>]/i.test( html )
				? __( '包含列表，结构清晰', 'agentsteamer-ai' )
				: __( '建议使用列表增强可读性', 'agentsteamer-ai' )
		);

		add(
			/<a\s[^>]*href=/i.test( html ) ? 'good' : 'warn',
			/<a\s[^>]*href=/i.test( html )
				? __( '包含链接', 'agentsteamer-ai' )
				: __( '建议添加内链或来源链接', 'agentsteamer-ai' )
		);

		// GEO: statistics.
		add(
			/\d+(\.\d+)?\s?%|\d{2,}/.test( text ) ? 'good' : 'warn',
			/\d+(\.\d+)?\s?%|\d{2,}/.test( text )
				? __( '包含数据/统计，利于被 AI 引用', 'agentsteamer-ai' )
				: __( '建议补充统计数据（GEO 可提升被引用率）', 'agentsteamer-ai' )
		);

		// GEO: quotes / citations.
		var cited = /<blockquote/i.test( html ) ||
			/(来源[:：]|据[^，。]{0,24}(报告|研究|数据|统计)|引用|参见)/.test( text );
		add(
			cited ? 'good' : 'warn',
			cited
				? __( '包含引述/来源，增强可信度', 'agentsteamer-ai' )
				: __( '建议补充引述或权威来源（GEO）', 'agentsteamer-ai' )
		);

		// GEO: direct answer at the top.
		var firstP = ( html.match( /<p[^>]*>([\s\S]*?)<\/p>/i ) || [] )[ 1 ] || '';
		var firstLen = len( stripTags( firstP ) );
		if ( firstLen > 0 && firstLen <= 120 ) {
			add( 'good', __( '开头段落简短，便于 AI 直接提取', 'agentsteamer-ai' ) );
		} else if ( firstLen > 120 ) {
			add( 'warn', __( '开头段落偏长，建议用 1–2 句直接回答主题', 'agentsteamer-ai' ) );
		} else {
			add( 'warn', __( '建议在开头用一句话直接回答主题', 'agentsteamer-ai' ) );
		}

		return checks;
	}

	function SidebarInner() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var postTitle = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, [] );
		var content = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostContent() || '';
		}, [] );
		var permalink = useSelect( function ( select ) {
			return select( 'core/editor' ).getPermalink() || '';
		}, [] );
		var siteTitle = useSelect( function ( select ) {
			var site = select( 'core' ).getSite();
			return site ? site.title : '';
		}, [] );

		var metaPair = useEntityProp( 'postType', postType, 'meta' );
		var meta = metaPair[ 0 ] || {};
		var setMeta = metaPair[ 1 ];

		var busyPair = useState( false );
		var busy = busyPair[ 0 ];
		var setBusy = busyPair[ 1 ];
		var msgPair = useState( '' );
		var msg = msgPair[ 0 ];
		var setMsg = msgPair[ 1 ];

		var schemaBusyPair = useState( false );
		var schemaBusy = schemaBusyPair[ 0 ];
		var setSchemaBusy = schemaBusyPair[ 1 ];
		var schemaMsgPair = useState( '' );
		var schemaMsg = schemaMsgPair[ 0 ];
		var setSchemaMsg = schemaMsgPair[ 1 ];

		var optBusyPair = useState( false );
		var optBusy = optBusyPair[ 0 ];
		var setOptBusy = optBusyPair[ 1 ];
		var optMsgPair = useState( '' );
		var optMsg = optMsgPair[ 0 ];
		var setOptMsg = optMsgPair[ 1 ];

		var linkBusyPair = useState( false );
		var linkBusy = linkBusyPair[ 0 ];
		var setLinkBusy = linkBusyPair[ 1 ];
		var linksPair = useState( [] );
		var links = linksPair[ 0 ];
		var setLinks = linksPair[ 1 ];
		var linkMsgPair = useState( '' );
		var linkMsg = linkMsgPair[ 0 ];
		var setLinkMsg = linkMsgPair[ 1 ];

		function setField( key, value ) {
			var next = Object.assign( {}, meta );
			next[ key ] = value;
			setMeta( next );
		}

		function generate() {
			setBusy( true );
			setMsg( '' );
			wp.apiFetch( {
				path: '/agentsteamer-ai/v1/generate/meta',
				method: 'POST',
				data: {
					post_id: postId,
					content: content,
					keyword: meta[ META.keyword ] || ''
				}
			} )
				.then( function ( res ) {
					var next = Object.assign( {}, meta );
					if ( res.title ) {
						next[ META.title ] = res.title;
					}
					if ( res.description ) {
						next[ META.description ] = res.description;
					}
					if ( res.keyword ) {
						next[ META.keyword ] = res.keyword;
					}
					setMeta( next );
					setMsg( __( '已生成，请检查后保存。', 'agentsteamer-ai' ) );
				} )
				.catch( function ( err ) {
					var m = ( err && err.message ) || __( '生成失败', 'agentsteamer-ai' );
					if ( err && ( err.code === 'invalid_json' || /JSON/i.test( m ) ) ) {
						m = __( '请求超时或返回异常（通常是模型响应过慢或服务器网关超时），请稍后重试或改用更快的模型。', 'agentsteamer-ai' );
					}
					setMsg( m );
				} )
				.then( function () {
					setBusy( false );
				} );
		}

		function extractSchema() {
			setSchemaBusy( true );
			setSchemaMsg( '' );
			wp.apiFetch( {
				path: '/agentsteamer-ai/v1/schema/extract',
				method: 'POST',
				data: { post_id: postId, content: content }
			} )
				.then( function ( res ) {
					var next = Object.assign( {}, meta );
					var filled = 0;
					if ( res.faq && res.faq.length ) {
						next[ META.schema_faq ] = res.faq.map( function ( item ) {
							return item.q + ' || ' + item.a;
						} ).join( '\n' );
						filled += res.faq.length;
					}
					if ( res.howto && res.howto.steps && res.howto.steps.length ) {
						next[ META.schema_howto ] = res.howto.steps.map( function ( s ) {
							return ( s.name || '' ) + ' || ' + ( s.text || '' );
						} ).join( '\n' );
						filled += res.howto.steps.length;
						if ( res.howto.name ) {
							next[ META.schema_howto_name ] = res.howto.name;
						}
					}
					setMeta( next );
					setSchemaMsg( filled ? __( '已提取，请检查后保存。', 'agentsteamer-ai' ) : __( '未提取到问答或步骤，可在下方手动填写。', 'agentsteamer-ai' ) );
				} )
				.catch( function ( err ) {
					setSchemaMsg( ( err && err.message ) || __( '提取失败', 'agentsteamer-ai' ) );
				} )
				.then( function () {
					setSchemaBusy( false );
				} );
		}

		function optimizeContent() {
			setOptBusy( true );
			setOptMsg( '' );
			wp.apiFetch( { path: '/agentsteamer-ai/v1/optimize/content', method: 'POST', data: { post_id: postId } } )
				.then( function ( res ) {
					setOptMsg( {
						text: __( '已生成优化草案，请在审阅队列中确认。', 'agentsteamer-ai' ),
						url: res.review_url
					} );
				} )
				.catch( function ( err ) {
					var m = ( err && err.message ) || __( '生成失败', 'agentsteamer-ai' );
					if ( err && ( err.code === 'invalid_json' || /JSON/i.test( m ) ) ) {
						m = __( '请求超时或返回异常，请稍后重试或改用更快的模型。', 'agentsteamer-ai' );
					}
					setOptMsg( { text: m } );
				} )
				.then( function () {
					setOptBusy( false );
				} );
		}

		function loadLinks() {
			setLinkBusy( true );
			wp.apiFetch( { path: '/agentsteamer-ai/v1/links/suggest', method: 'POST', data: { post_id: postId } } )
				.then( function ( res ) {
					setLinks( res.links || [] );
				} )
				.catch( function () {
					setLinks( [] );
				} )
				.then( function () {
					setLinkBusy( false );
				} );
		}

		function insertLinks() {
			if ( ! links.length ) {
				return;
			}
			setLinkMsg( { text: __( '正在生成审阅…', 'agentsteamer-ai' ) } );
			wp.apiFetch( { path: '/agentsteamer-ai/v1/links/insert', method: 'POST', data: { post_id: postId, links: links } } )
				.then( function ( res ) {
					setLinkMsg( {
						text: __( '已生成审阅草案，请在审阅队列确认。', 'agentsteamer-ai' ),
						url: res.review_url
					} );
				} )
				.catch( function ( err ) {
					setLinkMsg( { text: ( err && err.message ) || __( '插入失败', 'agentsteamer-ai' ) } );
				} );
		}

		var checks = analyze( meta, content, postTitle );
		var good = checks.filter( function ( item ) {
			return item.status === 'good';
		} ).length;
		var score = checks.length ? Math.round( ( good / checks.length ) * 100 ) : 0;
		var scoreColor = score >= 80 ? '#1a7f37' : ( score >= 50 ? '#b26a00' : '#b32d2e' );

		var rows = checks.map( function ( item, i ) {
			return el( 'li', { key: i, className: 'asi-check asi-' + item.status },
				el( 'span', { className: 'asi-dot' } ),
				el( 'span', null, item.label )
			);
		} );

		var previewTitle = meta[ META.title ] || postTitle;
		var previewDesc = meta[ META.description ] || '';

		return el(
			PluginSidebar,
			{
				name: SIDEBAR,
				title: __( 'AgentSteamer SEO / GEO', 'agentsteamer-ai' ),
				icon: 'chart-line'
			},
			el(
				PanelBody,
				{ title: __( 'GEO / SEO 优化评分', 'agentsteamer-ai' ), initialOpen: true },
				el(
					'div',
					{ className: 'asi-score-row' },
					el( 'span', { className: 'asi-score', style: { color: scoreColor, borderColor: scoreColor } }, score ),
					el( 'span', { className: 'asi-score-label' }, __( '分 / 100', 'agentsteamer-ai' ) )
				),
				el( 'ul', { className: 'asi-checks' }, rows ),
				el(
					'div',
					{ className: 'asi-ai-row' },
					el( Button, { variant: 'secondary', onClick: optimizeContent, disabled: optBusy },
						optBusy ? el( Spinner, null ) : __( 'AI 内容优化（生成审阅）', 'agentsteamer-ai' )
					)
				),
				optMsg ? el( Notice, { status: 'success', isDismissible: false }, [
					optMsg.text,
					optMsg.url ? el( 'a', { key: 'review', href: optMsg.url, target: '_blank', rel: 'noopener' }, ' ' + __( '前往审阅', 'agentsteamer-ai' ) ) : null
				] ) : null
			),
			el(
				PanelBody,
				{ title: __( 'SEO / GEO 字段', 'agentsteamer-ai' ), initialOpen: true },
				el( TextControl, {
					label: __( '焦点关键词', 'agentsteamer-ai' ),
					value: meta[ META.keyword ] || '',
					onChange: function ( v ) {
						setField( META.keyword, v );
					}
				} ),
				el( TextControl, {
					label: __( 'SEO 标题', 'agentsteamer-ai' ),
					value: meta[ META.title ] || '',
					help: len( meta[ META.title ] ) + ' / 60',
					onChange: function ( v ) {
						setField( META.title, v );
					}
				} ),
				el( TextareaControl, {
					label: __( 'Meta 描述', 'agentsteamer-ai' ),
					value: meta[ META.description ] || '',
					help: len( meta[ META.description ] ) + ' / 160',
					onChange: function ( v ) {
						setField( META.description, v );
					}
				} ),
				el( TextControl, {
					label: __( 'Canonical（留空自动）', 'agentsteamer-ai' ),
					value: meta[ META.canonical ] || '',
					onChange: function ( v ) {
						setField( META.canonical, v );
					}
				} ),
				el( ToggleControl, {
					label: __( 'noindex（不被搜索引擎收录）', 'agentsteamer-ai' ),
					checked: meta[ META.noindex ] === '1',
					onChange: function ( v ) {
						setField( META.noindex, v ? '1' : '0' );
					}
				} ),
				el(
					'div',
					{ className: 'asi-ai-row' },
					el( Button, { variant: 'secondary', onClick: generate, disabled: busy },
						busy ? el( Spinner, null ) : __( 'AI 生成标题 / 描述', 'agentsteamer-ai' )
					)
				),
				msg ? el( Notice, { status: 'success', isDismissible: false }, msg ) : null
			),
			el(
				PanelBody,
				{ title: __( '结构化数据 (Schema)', 'agentsteamer-ai' ), initialOpen: false },
				el( ToggleControl, {
					label: __( '输出 JSON-LD 结构化数据', 'agentsteamer-ai' ),
					checked: meta[ META.schema_disabled ] !== '1',
					onChange: function ( v ) {
						setField( META.schema_disabled, v ? '0' : '1' );
					}
				} ),
				el( ToggleControl, {
					label: __( '标记 Speakable（便于语音/AI 提取）', 'agentsteamer-ai' ),
					checked: meta[ META.speakable ] !== '0',
					onChange: function ( v ) {
						setField( META.speakable, v ? '1' : '0' );
					}
				} ),
				el( 'p', { className: 'asi-hint' }, __( 'FAQ / HowTo 每行一条，用「||」分隔：问题 || 答案；步骤 || 说明。', 'agentsteamer-ai' ) ),
				el( TextareaControl, {
					label: __( 'FAQ 问答', 'agentsteamer-ai' ),
					value: meta[ META.schema_faq ] || '',
					onChange: function ( v ) {
						setField( META.schema_faq, v );
					}
				} ),
				el( TextControl, {
					label: __( 'HowTo 标题', 'agentsteamer-ai' ),
					value: meta[ META.schema_howto_name ] || '',
					onChange: function ( v ) {
						setField( META.schema_howto_name, v );
					}
				} ),
				el( TextareaControl, {
					label: __( 'HowTo 步骤', 'agentsteamer-ai' ),
					value: meta[ META.schema_howto ] || '',
					onChange: function ( v ) {
						setField( META.schema_howto, v );
					}
				} ),
				el( TextareaControl, {
					label: __( '自定义 JSON-LD（高级）', 'agentsteamer-ai' ),
					value: meta[ META.schema_custom ] || '',
					onChange: function ( v ) {
						setField( META.schema_custom, v );
					}
				} ),
				el(
					'div',
					{ className: 'asi-ai-row' },
					el( Button, { variant: 'secondary', onClick: extractSchema, disabled: schemaBusy },
						schemaBusy ? el( Spinner, null ) : __( 'AI 从正文提取 FAQ / HowTo', 'agentsteamer-ai' )
					)
				),
				schemaMsg ? el( Notice, { status: 'success', isDismissible: false }, schemaMsg ) : null
			),
			el(
				PanelBody,
				{ title: __( '内部链接建议', 'agentsteamer-ai' ), initialOpen: false },
				el( Button, { variant: 'secondary', onClick: loadLinks, disabled: linkBusy },
					linkBusy ? el( Spinner, null ) : __( '分析相关文章', 'agentsteamer-ai' )
				),
				links.length === 0 ? el( 'p', { className: 'asi-hint' }, __( '基于标签、分类与关键词推荐相关文章，可复制 HTML 链接。', 'agentsteamer-ai' ) ) : null,
				el( 'ul', { className: 'asi-link-list' }, links.map( function ( item, i ) {
					var snippet = '<a href="' + item.url + '">' + item.anchor + '</a>';
					return el( 'li', { key: i },
						el( 'span', { className: 'asi-link-title' }, item.title ),
						el( 'code', { className: 'asi-link-snippet' }, snippet ),
						el( Button, { variant: 'tertiary', onClick: function () {
							if ( navigator.clipboard ) {
								navigator.clipboard.writeText( snippet );
							}
						} }, __( '复制', 'agentsteamer-ai' ) )
					);
				} ) ),
				links.length ? el( 'div', { className: 'asi-ai-row' },
					el( Button, { variant: 'secondary', onClick: insertLinks }, __( '插入到正文（生成审阅）', 'agentsteamer-ai' ) )
				) : null,
				linkMsg ? el( Notice, { status: 'success', isDismissible: false }, [
					linkMsg.text,
					linkMsg.url ? el( 'a', { key: 'r', href: linkMsg.url, target: '_blank', rel: 'noopener' }, ' ' + __( '前往审阅', 'agentsteamer-ai' ) ) : null
				] ) : null
			),
			el(
				PanelBody,
				{ title: __( '搜索与社交预览', 'agentsteamer-ai' ), initialOpen: false },
				el( 'div', { className: 'asi-serp' },
					el( 'div', { className: 'asi-serp-url' }, permalink || siteTitle ),
					el( 'div', { className: 'asi-serp-title' }, previewTitle ),
					el( 'div', { className: 'asi-serp-desc' }, previewDesc || __( '（无 Meta 描述）', 'agentsteamer-ai' ) )
				),
				el( 'div', { className: 'asi-social' },
					el( 'div', { className: 'asi-social-title' }, previewTitle ),
					el( 'div', { className: 'asi-social-desc' }, previewDesc ),
					el( 'div', { className: 'asi-social-site' }, siteTitle )
				)
			)
		);
	}

	function Sidebar() {
		return el( Fragment, null,
			PluginSidebarMoreMenuItem
				? el( PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'chart-line' },
					__( 'AgentSteamer SEO / GEO', 'agentsteamer-ai' ) )
				: null,
			el( SidebarInner, null )
		);
	}

	registerPlugin( 'agentsteamer-ai', { render: Sidebar } );
} )( window.wp );
