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

window.addEventListener("DOMContentLoaded", () => {
    if (isIframe()) {
        loop();
    }
});

function isIframe() {
    return window.location !== window.parent.location;
}

// bypass third-party DOM changes on load
function loop(n = 10) {
    refineIframe();
    setTimeout(() => {
        if (--n) {
            loop(n);
        }
    }, 200);
}

function refineIframe() {
    const items = [
        { selector: "#header", propName: "display", value: "none" },
        { selector: "#filelist-header", propName: "display", value: "none" },
        { selector: "#body-user", propName: "height", value: "100%" },
        { selector: "#content", propName: "padding-top", value: 0 },
        { selector: "#content-vue", propName: "padding-top", value: 0 },
        { selector: "#app-navigation", propName: "top", value: 0 },
        { selector: "#app-navigation-vue", propName: "top", value: 0 },
        { selector: "#controls", propName: "top", value: 0 },
        { selector: ".header", propName: "top", value: 0 },
        { selector: "#filestable thead", propName: "top", value: "44px" },
    ];
    for (const { selector, propName, value } of items) {
        const element = document.querySelector(selector);
        if (element) {
            element.style.setProperty(propName, value, "important");
        }
    }
}
