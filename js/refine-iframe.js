/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

"use strict";

// Query param used by Watcha to flag a top-level "warm-up" window whose only
// purpose is to establish the Nextcloud SSO session (cf. Watcha DocumentPanel).
const WARMUP_PARAM = "watcha_warmup";
const WARMUP_MESSAGE = "watcha_warmup-ok";
const WIDGET_READY_MESSAGE = "watcha_widget-ready";

function refine() {
    const params = new URLSearchParams(window.location.search);
    if (window.self !== window.top) {
        refineWidget();
    }
    if (params.has("watcha_doc-selector")) {
        refineDocumentSelector();
    }
}

/**
 * When Watcha opens this page top-level (in a popup) to warm up the Nextcloud
 * SSO session, reaching this script means the SSO chain
 * (Nextcloud -> Keycloak -> CAS) has completed and the session cookie is set.
 * We notify the opener, which then closes this window and loads the document
 * iframe. This avoids the browser's Local Network Access (LNA/PNA) block that
 * hits the SSO redirect when it is performed from within the iframe.
 *
 * @returns {boolean} true if this is a warm-up window (no further refine needed)
 */
function handleWarmup() {
    const params = new URLSearchParams(window.location.search);
    if (params.has(WARMUP_PARAM) && window.self === window.top && window.opener) {
        const origin = (OC.appConfig.watcha && OC.appConfig.watcha.origin) || "";
        window.opener.postMessage(WARMUP_MESSAGE, origin);
        return true;
    }
    return false;
}

function refineWidget() {
    const style = `
        #header {
            display: block !important;
        }

        .header-start {
            visibility: hidden !important;
            width: 200px !important;
        }

        .header-end {
            position: absolute !important;
            right: 0;
        }
        
        .header-end > :not(:first-child) {
            display: none !important;
        }

        .filelist-header {
            display: none !important;
        }

        #body-user,
        #app-navigation-vue {
            height: 100% !important;
        }

        #content,
        #content-vue,
        #app-navigation {
            padding-top: 50px !important;
            margin-top: 0 !important;
            height: 100% !important;
            border-radius: 0 !important;
        }

        #app-navigation,
        #app-navigation-vue,
        #controls,
        .header {
            top: 0 !important;
        }

        #filestable > thead {
            top: 44px !important;
        }`;
    insertStyle(style);
}

function refineDocumentSelector() {
    const style = `
        .app-sidebar,
        #app-sidebar,
        #app-navigation-toggle,
        #view-toggle,
        #headerSize,
        .column-selection,
        .selection,
        .fileactions,
        .filesize {
            display: none !important;
        }

        .files-controls {
            padding-left: 0 !important;
        }
        
        #app-content {
            transform: none !important;
        }`;
    insertStyle(style);
}

function insertStyle(style) {
    let element = document.createElement("style");
    element.innerHTML = style;
    document.head.appendChild(element);
}

function postUrl(prevUrl) {
    const url = window.location.href;
    if (prevUrl && url !== prevUrl) {
        window.parent.postMessage(url, OC.appConfig.watcha?.origin || "");
    }
    // HACK: to detect URLSearchParams changes that do not trigger a page reload
    setTimeout(() => {
        postUrl(url);
    }, 200);
}

/**
 * Signal the Watcha parent that the embedded Nextcloud widget has loaded
 * successfully in the iframe. Watcha uses this to detect when the in-iframe SSO
 * redirect was NOT blocked (so no top-level warm-up popup is needed).
 */
function notifyWidgetReady() {
    if (window.self !== window.top) {
        const origin = (OC.appConfig.watcha && OC.appConfig.watcha.origin) || "";
        window.parent.postMessage(WIDGET_READY_MESSAGE, origin);
    }
}

// A warm-up window has no UI purpose: just signal the opener and stop.
if (!handleWarmup()) {
    refine();
    notifyWidgetReady();
    postUrl();
}
