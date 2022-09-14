/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 */

"use strict";

function embed() {
    const params = new URLSearchParams(window.parent.location.search);
    const flavor = params.get("flavor");
    switch (flavor) {
        case "file-explorer":
            embedFileExplorer();
            break;
        case "widget":
            const isCurrentChunck = chunck =>
                new RegExp(`^${OC.webroot}(/index.php)?/${chunck}`).test(window.location.pathname);
            const isCurrentApp = appName => isCurrentChunck("apps/" + appName);
            if (isCurrentApp("files")) {
                embedFilesWidget();
            } else if (isCurrentApp("calendar")) {
                embedCalendarWidget();
            } else if (isCurrentApp("tasks")) {
                embedTasksWidget();
            } else if (isCurrentChunck("s/[A-Za-z0-9]+?")) {
                embedDirectLinkFilesWidget();
            }
            break;
    }
}

function embedFileExplorer() {
    _hideFilesToolbar();
    const style = `
        #filelist-header,
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

        #body-user {
            height: 100% !important;
        }

        #filestable > thead {
            top: 44px !important;
        }
        
        #app-content {
            transform: none !important;
        }`;
    _injectStyle(style);
}

function embedFilesWidget() {
    _hideFilesToolbar();
}

function embedCalendarWidget() {
    const style = `
        #header {
            display: none !important;
        }

        #app-navigation-vue {
            top: 0 !important;
        }

        #content-vue {
            padding-top: 0 !important;
        }`;
    _injectStyle(style);
}

function embedTasksWidget() {
    const style = `
        #header {
            display: none !important;
        }

        #app-navigation-vue,
        .header {
            top: 0 !important;
        }

        #content-vue {
            padding-top: 0 !important;
        }`;
    _injectStyle(style);
}

function embedDirectLinkFilesWidget() {
    const style = `
        #header {
            justify-content: center !important;
            background-color: var(--color-main-background) !important;
            background-image: none !important;
        }

        #header > .header-left,
        #header-secondary-action {
            display: none !important;
        }

        #header-primary-action > a {
            background-color: rgb(0, 179, 179) !important;
            border-color: rgb(0, 179, 179) !important;
            border-radius: 8px !important;
        }`;
    _injectStyle(style);
}

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
        }`;
    _injectStyle(style);
}

function _injectStyle(style) {
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

embed();
postUrl();
