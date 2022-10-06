/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
 *
 * @license AGPL-3.0-or-later
 *
 */

'use strict'

import { generateUrl } from '@nextcloud/router'

/**
 *
 */
function embed() {
	const params = new URLSearchParams(window.parent.location.search)
	const flavor = params.get('flavor')
	const routeStartsWith = chunck => window.location.pathname.startsWith(generateUrl(chunck))
	const isCurrentApp = appName => routeStartsWith('apps/' + appName)
	switch (flavor) {
	case 'document-picker':
		console.debug('[watcha] embedding document picker')
		watchDocumentSelection()
		embedDocumentPicker()
		break
	case 'widget':
		if (isCurrentApp('files')) {
			console.debug('[watcha] embedding files widget')
			embedFilesWidget()
		} else if (isCurrentApp('onlyoffice')) {
			console.debug('[watcha] embedding ONLYOFFICE widget')
			embedOnlyofficeWidget()
		} else if (isCurrentApp('calendar')) {
			console.debug('[watcha] embedding calendar widget')
			embedCalendarWidget()
		} else if (isCurrentApp('tasks')) {
			console.debug('[watcha] embedding tasks widget')
			embedTasksWidget()
		} else if (routeStartsWith('s/')) {
			console.debug('[watcha] embedding direct link files widget')
			embedDirectLinkFilesWidget()
		}
		break
	}
}

/**
 *
 */
function embedDocumentPicker() {
	_hideFilesToolbar()
	const style = `
        #app-navigation,                /* left panel */
        #app-navigation-toggle,
        #filelist-header,               /* notes section */
        #controls span.icon-shared,     /* breadcrumb share icon */
        #view-toggle,                   /* top right button */
        #selectedActionsList,
        .fileactions,
        #rightClickMenus,
        aside {                         /* right panel */
            display: none !important;
        }

        #controls {
            padding-left: 0 !important;
        }

        #app-content {
            margin-left: 0 !important;
            transform: none !important;
        }

        tr[data-type="file"] > td:not(.selection) {
            pointer-events: none;
        }`
	_injectStyle(style)
	_showParentIframe()
}

/**
 *
 */
function embedFilesWidget() {
	_hideFilesToolbar()
	_watchForBreadcrumb()
	const style = `
        #app-navigation > ul > li:not(.pinned) {
            display: none !important;
        }`
	_injectStyle(style)
	const params = new URLSearchParams(window.location.search)
	if (params.has('openfile')) {
		_watchForViewer()
	} else {
		_showParentIframe()
	}
}

/**
 *
 */
function embedOnlyofficeWidget() {
	const style = `
		#header {
			display: none !important;
		}

		#content {
			padding-top: 0 !important;
            height: 100% !important;
		}`
	_injectStyle(style)
	_showParentIframe()
}

/**
 *
 */
function embedCalendarWidget() {
	_hideCalendarToolbar()
	_showParentIframe()
}

/**
 *
 */
function embedTasksWidget() {
	_hideCalendarToolbar()
	const style = `
        .header {
            top: 0 !important;
        }`
	_injectStyle(style)
	_showParentIframe()
}

/**
 *
 */
function embedDirectLinkFilesWidget() {
	const style = `
		#header {
			display: none !important;
		}

        #content {
            padding-top: 0 !important;
            height: 100% !important;
        }

		#controls {
			top: 0 !important;
		}

        #filestable > thead {
            top: 44px !important;
        }

		#onlyofficeFrame {
            height: 100% !important;
        }`
	_injectStyle(style)
	_showParentIframe()
}

/**
 *
 */
function _hideFilesToolbar() {
	const style = `
        #header {
            display: none !important;
        }

        #app-navigation,
        #controls {
            top: 0 !important;
        }

        #content {
            padding-top: 0 !important;
        }

        #body-user,
        #app-navigation {
            height: 100% !important;
        }

        #filestable > thead {
            top: 44px !important;
        }`
	_injectStyle(style)
}

/**
 *
 */
function _hideCalendarToolbar() {
	const style = `
        #header {
            display: none !important;
        }

        #app-navigation-vue {
            height: 100vh !important;
        }

        #content-vue {
            padding-top: 0 !important;
        }`
	_injectStyle(style)
}

/**
 *
 */
function _watchForBreadcrumb() {
	console.debug('[watcha] watching for breadcrumb')
	const controls = document.getElementById('controls')
	controls.style.visibility = 'hidden'
	const callback = mutationsList => {
		for (const mutation of mutationsList) {
			for (const node of mutation.addedNodes) {
				if (node.className === 'breadcrumb') {
					observer.disconnect()
					_hideBreadcrumbAncestors()
					controls.style.visibility = 'visible'
					return
				}
			}
		}
	}
	const observer = new MutationObserver(callback)
	const config = { childList: true }
	observer.observe(controls, config)
}

/**
 *
 */
function _hideBreadcrumbAncestors() {
	console.debug('[watcha] hidding ancestors in the breadcrumb')
	const ancestorSelector = '#controls > .breadcrumb > :is(.crumbmenu, .ui-droppable)'
	const n = document.querySelectorAll(ancestorSelector).length
	const style = `
    #controls > .breadcrumb > .crumb {
        display: inline-flex !important;
    }
    ${ancestorSelector}:not(:nth-child(n+${n + 1})) {
        display: none !important;
    }`
	_injectStyle(style)
}

/**
 *
 * @param {string} style CSS rules to apply to the current page
 */
function _injectStyle(style) {
	const element = document.createElement('style')
	element.innerHTML = style
	document.head.appendChild(element)
}

/**
 *
 */
function _watchForViewer() {
	console.debug('[watcha] watching for viewer')
	const callback = mutationsList => {
		for (const mutation of mutationsList) {
			for (const node of mutation.addedNodes) {
				if (node.id === 'viewer' && node.childNodes.length) {
					console.debug('[watcha] viewer found')
					observer.disconnect()
					_showParentIframe()
					return
				}
			}
		}
	}
	const observer = new MutationObserver(callback)
	const config = { childList: true }
	observer.observe(document.body, config)
}

/**
 *
 */
function _showParentIframe() {
	console.debug('[watcha] showing parent iframe')
	const iframe = window.parent.document.getElementById('embededIframe')
	iframe.style.visibility = 'visible'
}

/**
 *
 */
function watchDocumentSelection() {
	console.debug('[watcha] watching document selection')
	document.getElementById('select_all_files').addEventListener('input', () => {
		postSelectedDocuments()
	})
	const fileList = document.getElementById('fileList')
	fileList.addEventListener('input', ({ target }) => {
		if (Array.from(fileList.querySelectorAll('td.selection > .selectCheckBox')).includes(target)) {
			postSelectedDocuments()
		}
	})
}

/**
 *
 */
function postSelectedDocuments() {
	// wait for OCA.Files.App.fileList._selectedFiles to be updated
	window._.defer(() => {
		const documents = OCA.Files.App.getCurrentFileList().getSelectedFiles()
		window.top.postMessage(documents, OC.appConfig.watcha?.origin || '')
		console.debug('[watcha] selected documents:', documents)
	})
}

if (
	// this window must be an iframe
	window.self !== window.parent
	// this window must be embedded by the Watcha connector for Nextcloud
	&& window.parent.location.pathname === generateUrl('/apps/watcha/embed')
) {
	embed()
}
