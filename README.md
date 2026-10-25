# Deferendum for Nextcloud — 0.3.0

A mobile-first workspace for selected Deferendum groups, using matte glass, subtle aurora colors, shared icons and accessible confirmations. Help combines a four-step tour, 20 searchable questions and feedback links. The Dashboard remains read-only.

Connect an existing Deferendum account from any compatible Nextcloud instance with a publicly reachable HTTPS base URL. Each instance proves ownership, then each member selects their own groups and permissions. The interface is available in English, Romanian, French, Spanish and Portuguese. Member-written content is never translated automatically.

## Update without losing the existing connection

The app ID remains `deferendum`. An ordinary update from 0.2.4 or 0.3.0-beta.1 preserves local registration, per-user connections, preferences and pending-operation metadata. This release adds no disable/uninstall repair hook or data migration. Do not uninstall or replace the registration as an update step. Retain a verified backup of the installed source, Nextcloud configuration, appconfig and per-user preferences before a live update, and use the standard Nextcloud app update mechanism.

Withdrawing access and replacing an instance registration remain separate explicit user actions. The UI update does not expand an existing grant or silently reconnect a member.

## Interface

Select a group and apply the desired active/history and content filters. Open its workspace or a decision. The current stage and source capabilities determine the controls. Secondary forms expand on request; voting keeps the exact wording and confirmation visible. A countdown reaching zero does not advance a phase locally: refresh the source.

The native Nextcloud app header and account remain under Nextcloud control. Scoped styles support its dark theme, keyboard focus and reduced-motion/transparency preferences. No new frontend framework, external font, tracking or remote asset dependency is introduced.

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

The hosted service and App Store package are deployed separately. Engineering validation covers real OAuth with two instance clients and restricted PostgreSQL principals in disposable fixtures, grant and membership checks, canonical commands, safe uncertain outcomes and the signed connector. The live proxy suppresses access logging for integration routes. These engineering checks do not represent an independent external security audit or a completed volunteer study. The App Store version and hosted availability should be checked when troubleshooting.

Disabling or removing the app does not perform credential erasure or remote revocation. Users should withdraw connections before removal or revoke them in Deferendum, and resolve uncertain operations separately. A compromised Nextcloud server must be revoked at the source. See the lifecycle-retention notes above.

The connector is AGPL-3.0-or-later; see COPYING. All connector PHP, JavaScript, styles, templates, translations and license notices are included as corresponding source; there is no separate compiled frontend or dependency bundle. This does not change the hosted backend or native app licenses. The private hosted backend, test accounts, fixtures, signing keys and operational configuration are not part of this connector distribution. This is not a self-hosted Deferendum service or a Nextcloud endorsement, and it does not replace legal voting or organizational procedures.

Corresponding connector source and public issue tracker: https://github.com/nicdragoi/deferendum-nextcloud. Report ordinary bugs with the version, generic error and failure time at https://github.com/nicdragoi/deferendum-nextcloud/issues. Report suspected security issues privately to nick.dragoi@deferendum.com; do not post an exploit or private information in a public issue. Never send passwords, tokens, client secrets, cookies or private group content.
