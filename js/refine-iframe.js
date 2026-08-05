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
    hideRootCrumb();
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
        .filesize,
        .files-list__column.files-list__row-checkbox,
        .files-list__row-checkbox,
        .files-list__header-recommendations {
            display: none !important;
        }

        .files-controls {
            padding-left: 0 !important;
        }

        #app-content {
            transform: none !important;
        }`;
    insertStyle(style);
    indentRowNames();
}

function indentRowNames() {
    const apply = () => {
        document.querySelectorAll(".files-list__row-name").forEach((cell) => {
            cell.style.setProperty("padding-inline-start", "8px", "important");
        });
    };
    apply();
    new MutationObserver(apply).observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
}

function hideRootCrumb() {
    // The root breadcrumb navigates out of the shared folder to the user's own
    // Files root, exposing their whole personal tree. Nextcloud gives it no
    // stable marker of its own: every crumb carries the class `vue-crumb` and a
    // `data-crumb-id` whose value is a Vue instance id (`nc-vue-N`), assigned
    // incrementally at runtime. That id shifts as soon as extra components are
    // instantiated — e.g. when a folder is renamed — which is why the former
    // hard-coded `data-crumb-id="nc-vue-5"` selector silently stopped matching
    // and the root crumb reappeared, differently per instance.
    //
    // The root is simply the FIRST crumb, so select it positionally (document
    // order) and keep it hidden across the re-renders that a rename triggers.
    // Only the first crumb is hidden: any deeper crumb belongs to the shared
    // folder itself and must stay, so navigating back up *within* the share works.
    const hideFirstCrumb = () => {
        const rootCrumb = document.querySelector(".vue-crumb");
        if (rootCrumb) {
            rootCrumb.style.setProperty("display", "none", "important");
        }
    };
    hideFirstCrumb();
    new MutationObserver(hideFirstCrumb).observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
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