# Deferendum — participation candidate 0.2.0

Unpublished development candidate; not an App Store release or a production-ready integration. The hosted v2 participation adapter and its canonical transaction port are implemented locally but are not deployed. Synthetic PostgreSQL 17 acceptance and browser fixture checks do not establish production readiness. The separate existing 0.1.0 read-only pilot is unchanged.

## Native workspace

The Dashboard remains a bounded read-only overview. This app adds concern creation and urgency rating; pro/con arguments, reaction counts and comments; question wording proposals, support and accommodation review; governing rules and amendment proposals; and explicitly confirmed immutable ballots. Likes/dislikes are reactions, not votes or consensus. Comments have no reaction score. Source roles, governing rules, eligibility, phases and locked versions remain authoritative. Nextcloud administrator status does not grant a Deferendum governing role.

Member-written text is rendered literally without automatic translation. Structured authors and named ballots are omitted, but texts can still contain personal information. The interface is localized in RO, EN, FR, ES and PT.

## Registration and consent

Each instance must register its own public HTTPS base URL, including a subdirectory where applicable. Temporary ownership proof and exact callbacks bind its client. The connector is not tied to an instance-specific domain. Intranet-only servers and private destinations remain unsupported by this public enrollment model; this is not a configurable arbitrary API proxy.

New v2 registration exposes separate overview, discussion and participation permissions. Participation requires both reading permissions. Each user explicitly selects 1–100 accessible groups for no more than 30 days. New membership does not enlarge the grant automatically.

The new issuer is pinned to https://app.deferendum.com/integrations/nextcloud-participation, with its own v2 API audience. Legacy connections retain the separate https://app.deferendum.com/integrations/nextcloud issuer and v1 audience. Token exchange, refresh and revocation always use the exact registered issuer; the app cannot be configured as an arbitrary API proxy. A user can decline requested permissions; only the actual granted subset is stored, and refresh cannot enlarge it.

Existing v1 clients and grants remain read-only on v1 routes. An administrator explicitly replaces an old registration before v2 is available, requiring members to reconnect. A user must explicitly withdraw and reconnect to obtain broader permissions. No update silently enlarges a grant.

## Actions and uncertain outcomes

Every action shows its exact group, payload and current source wording/version for confirmation. Confirmed commands use one UUID and are sent once. There are no optimistic vote updates or automatic write retries.

Before forwarding, the server retains owner-bound encrypted pending metadata only: operation UUID, group, command, request hash and time. It stores no private draft or command payload. An uncertain response blocks new commands across reloads and can be checked manually by operation UUID. A not-found receipt is not proof of failure and cannot trigger a duplicate retry. A matching committed receipt or definitive pre-commit denial resolves the pending state.

Pending metadata also retains the original issuer, public instance base and an opaque actor/instance connection binding supplied by Deferendum. A new explicitly authorized connection can resolve a historical receipt only for the same source account and verified instance base. Another source account or an unknown legacy binding cannot erase the pending record.

The separate **Resolve operation outcome** control requires confirmation. It either retrieves a confirmed receipt or atomically fences an uncommitted operation so that a delayed original request cannot arrive later. It never resubmits the original action. Only a matching authoritative committed or not-committed result clears the local pending state. Source-side historical receipt and fence wiring has passed isolated synthetic PostgreSQL concurrency and reconnect tests; production deployment and independent review remain separate requirements.

Hosted receipt/fence recovery is limited to the authorization recovery window. After more than 31 days, an owner can separately confirm **Discard expired pending operation** after reconnecting the original Deferendum account on the same instance. This removes only the local block. The original outcome remains unknown: check Deferendum first, because the action may already have committed. No operation is automatically discarded, undone or retried.

## Privacy and release boundary

No Deferendum password or browser bearer is stored. Credentials and pending metadata are encrypted for their local owner. No persistent private-content cache or default telemetry is added. Prior private content is cleared when a read fails or the page is hidden. The Nextcloud administrator remains part of the trust boundary: encryption does not hide secrets from an administrator controlling the server and its keys.

Before public use, review reverse-proxy/application logs and backups independently, resolve OAuth/query/header leakage, and complete canonical database transaction wiring, concurrency/role/phase authorization tests, two-instance runtime and five-language browser acceptance, independent security review, recovery and rollback, consenting volunteer validation, public corresponding source and support/privacy documentation, signing and the exact App Store submission. Local mocks or fixture tests do not establish public readiness.

Uninstall removes local credentials; it does not prove that every remote grant was revoked. Users should withdraw connections before uninstalling or revoke them in Deferendum. A compromised Nextcloud server must be revoked at the source.

The connector is AGPL-3.0-or-later; see COPYING. All connector PHP, JavaScript, styles, templates, translations and license notices are included as corresponding source; there is no separate compiled frontend or dependency bundle. This does not change the hosted backend or native app licenses. The private hosted backend, test accounts, fixtures, signing keys and operational configuration are not part of this connector distribution. This is not a self-hosted Deferendum service or a Nextcloud endorsement, and it does not replace legal voting or organizational procedures.

Corresponding connector source and public issue tracker: https://github.com/nicdragoi/deferendum-nextcloud. Report ordinary bugs with the version, generic error and failure time at https://github.com/nicdragoi/deferendum-nextcloud/issues. Report suspected security issues privately to nick.dragoi@deferendum.com; do not post an exploit or private information in a public issue. Never send passwords, tokens, client secrets, cookies or private group content.
