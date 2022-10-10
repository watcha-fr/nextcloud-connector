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

function refine() {
    const params = new URLSearchParams(window.location.search);
    if (window.self !== window.top) {
        refineWidget();
    }
    if (params.has("watcha_doc-selector")) {
        refineDocumentSelector();
    }
}

function refineWidget() {
    const style = `
        #header,
        #filelist-header {
            display: none !important;
        }

        #body-user {
            height: 100% !important;
        }

        #content,
        #content-vue {
            padding-top: 0 !important;
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
        #view-toggle,
        #headerSelection,
        #headerSize,
        .selection,
        .fileactions,
        .filesize {
            display: none !important;
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

refine();
postUrl();
