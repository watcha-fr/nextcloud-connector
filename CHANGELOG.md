# Changelog

## 0.9.0

Compatibilité Nextcloud 34. **Cette version ne fonctionne plus sur Nextcloud 33** :
le socle qu'elle utilise n'existe plus que sous sa forme 34.

### Fixed

- Nextcloud 34 a supprimé les accesseurs `\OC::$server->getXxx()` — il ne reste que
  `getL10N()`, `getUserFolder()` et `getWebRoot()`. Le connecteur en appelait vingt,
  et les deux conséquences étaient silencieuses à la lecture mais franches à l'usage :

  - `App::extendJsConfig()` plantait dans le hook `\OCP\Config`/`js`
    (`Call to undefined method OC\Server::getConfig()`), donc `oc_appconfig.watcha`
    n'atteignait plus la page. `refine-iframe.js` appelait alors
    `postMessage(url, "")` — une `SyntaxError` dure — et **le panneau documents du
    salon cessait de fonctionner**.
  - `Dav::getServerInstance()` aurait échoué au premier appel, emportant avec lui
    **toute création ou tout partage d'agenda**.

  Les appels passent à `\OCP\Server::get()`, en calquant le
  `apps/dav/appinfo/v1/caldav.php` de Nextcloud 34 dont ce bloc est une copie :
  garder la copie diffable avec l'amont est ce qui rend la prochaine montée de
  version lisible.

- `getAppValue('dav', 'sendInvitations', 'yes')` devient
  `IAppConfig::getValueBool('dav', 'sendInvitations', true)`, comme l'amont — le
  défaut est passé de la chaîne `'yes'` à un vrai booléen.

- `Dav::getServerInstance(IDBConnection $connection = null, IUser $user = null)`
  devient `?IDBConnection` / `?IUser`. La dépréciation des paramètres implicitement
  nullables encombrait déjà les logs en PHP 8.4 ; elle sera fatale en PHP 9.

### Changed

- Intervalle de compatibilité : `min-version="34.0"`, `max-version="34.0.4"`.

## 0.8.0

### Fixed

- A room's document space was located by **folder name**, which is not stable.
  Each recipient of a Nextcloud share may rename their own mount
  (`oc_share.file_target` on the child share) and Nextcloud appends a suffix on
  collision, so the same folder is `/Nouveau dossier` for 24 members,
  `/Facilitateurs` for one and `/FACILITATEURS` for another — and the name
  resolved for one member produced a 404 for the next.

  New `GET /rooms/{roomId}/folder` route returns the Nextcloud **file id**, which
  is stable, together with the path as currently mounted for the calling user and
  a status the client can act on (`ok`, `rejected`, `not-member`, `deleted`,
  `no-share`). Resolution uses `IShare::getNodeId()` and `getTarget()`, so no
  WebDAV round trip is involved on either side.

  See [docs/room-folder-resolution.md](docs/room-folder-resolution.md).

### Changed

- Room group naming is now defined once, in `OCA\Watcha\RoomGroup`, instead of
  being duplicated between the calendar controller and Synapse's convention.

### Deprecations

- `@NoAdminRequired` / `@NoCSRFRequired` docblock annotations replaced by the
  `OCP\AppFramework\Http\Attribute\*` attributes, and the deprecated `UserId` DI
  alias replaced by `userId`. Both were emitting deprecation warnings on every
  request.

### Not shipped, and why

An earlier iteration of this release added a share-acceptance service, a
`UserAddedEvent` listener, an `occ watcha:shares:accept-pending` command and a
member-sync route, on the assumption that members joining a room after its folder
was shared were left with an unaccepted share. **That diagnosis was wrong and the
work has been reverted.**

Two findings retired it:

- an instance where invitations work runs the *same* app version, with no
  membership listener and the same acceptance limited to `createShare` — so this
  code could not explain a difference in behaviour between deployments;
- the metric behind the diagnosis does not measure access. Nextcloud creates
  per-recipient child shares **lazily**, only when a recipient renames, moves or
  rejects their mount; their absence is the normal state, since access is carried
  by the parent group share. The ratio was 0.55 unaccepted pairs per share on the
  deployment reported as broken versus 0.50 on a healthy one.

The one per-recipient state that really denies access is an explicit
`STATUS_REJECTED`, which the resolver above reports. The real cause of the
reported symptom is in Synapse, not here.
