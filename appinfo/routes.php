<?php

declare(strict_types=1);

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

/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> OCA\Watcha\Controller\PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */

return [
    'routes' => [
        [
            'name' => 'calendar#addUser',
            'url'  => '/users',
            'verb' => 'POST',
        ],
        [
            'name' => 'calendar#removeUser',
            'url'  => '/users/{userId}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'calendar#list',
            'url'  => '/users/{userId}/calendars',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#get',
            'url'  => '/users/{userId}/calendars/{calendarId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#reorder',
            'url'  => '/users/{userId}/calendars/{calendarId}/top',
            'verb' => 'PUT',
        ],
        [
            'name' => 'calendar#share',
            'url'  => '/users/{userId}/calendars/{calendarId}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'calendar#createAndShare',
            'url'  => '/calendars',
            'verb' => 'POST',
        ],
        [
            'name' => 'calendar#unShare',
            'url'  => '/calendars',
            'verb' => 'DELETE'
        ],
        [
            'name' => 'calendar#rename',
            'url'  => '/calendars/displayname',
            'verb' => 'PUT',
        ],
    ]
];
