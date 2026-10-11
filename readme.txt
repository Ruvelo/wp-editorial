=== Ruvelo Editorial ===
Contributors: francoisbultez
Tags: editorial, workflow, review, approval, notes
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Review and approval on top of WordPress Notes: a review queue, assigned reviewers, approvals, and optional gates that hold publishing until approved.

== Description ==

WordPress now has Notes: your team can comment on any block of a draft. What it doesn't have is a decision. Who should look at this post? Have they? Can it go out? Ruvelo Editorial adds that layer and nothing more. It works with core Notes, never instead of them, and it sits beside WordPress's own statuses (draft, pending, scheduled, published) instead of replacing them.

**Ask for a review.** From the Review panel in the editor, pick one or more reviewers and an optional due date. They're told by email, grouped so a busy morning is one email, not twelve.

**Approve or request changes.** Reviewers decide in the same panel, with an optional message. A change request can also become a core note on the block it's about, so the author finds it in the Notes sidebar like any other note. After the changes, the author asks again and a new round starts.

**A queue for everyone.** Reviews → Review queue shows what's waiting for your review, the changes requested on your posts, and what's approved and ready to publish, with approvals, open notes, due dates and filters. The menu shows how many posts are waiting for you, and a dashboard widget shows what's next.

**Publish gates, if you want them.** Per post type, hold publishing and scheduling until a post has the approvals it needs (one, two, or more), and optionally until its notes are resolved. The editor explains why the Publish button waits. The rule is enforced on the server too, so the REST API, quick edit, bulk edit and WP-CLI can't skip it. Administrators can publish past the gate with a reason, which is logged and sent to the reviewers.

**A history for every post.** Who asked, who approved, who requested changes and what they said, who published, and every override, with dates.

**For developers.** A REST API (`ruvelo-editorial/v1`), WP-CLI (`wp ruvelo-editorial request|approve|request-changes|status|queue|history`), Abilities API registrations so AI agents can read review states and the queue (and, as a reviewer, approve or request changes), and actions and filters for every transition. Delivers to Ruvelo Inbox when it's active.

= Our promises =

* **No nags, no upsells, no "pro" version.** No admin notices outside the plugin's own screens, no review requests, no ads, no redirect after activation. It's complete and free, and it will stay that way.
* **No tracking and no remote requests.** The plugin never phones home and loads nothing from anywhere else: no fonts, scripts, images, banners or Gravatars (people are shown by their initials). The only emails it sends are the ones you turn on.
* **Updates come only from WordPress.org and Composer**, through WordPress's own updater. Ruvelo will never sell this plugin, its WordPress.org account or its users.
* **Your data stays yours.** Deleting the plugin keeps review states and history unless you tick "also delete review states, history and settings". Core notes are never deleted. Scheduled emails are always cleared.
* **Accessible and light.** Labels, focus states, keyboard support and live announcements, aiming at WCAG 2.2 AA. Every action also works as a plain form on the review screen, without JavaScript. The review screens can be light, dark or follow your device.

Ruvelo Editorial is built by [Valtry](https://valtry.co.uk/?utm_source=ruvelo&utm_medium=wordpress&utm_campaign=wp-editorial), the website security scanner, as part of Ruvelo, its free and open-source extensions for WordPress and Laravel. The code, issues and roadmap are on [GitHub](https://github.com/Ruvelo/wp-editorial).

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or with `wp plugin install ruvelo-editorial --activate`.
2. Reviews are on for posts and pages straight away. Open a draft, find the **Review** panel in the sidebar and ask for a review.
3. To hold publishing until approval, go to Reviews → Settings and tick **Hold publishing until approved** for a post type, with the number of approvals it needs.

Editors and administrators can review by default; administrators can publish past a gate. Give the `ruvelo_review_posts` capability to any role to make its users reviewers.

With Composer: `composer require ruvelo/wp-editorial`.

== Frequently Asked Questions ==

= Does it replace WordPress's post statuses? =

No. A post's review state (Not in review, In review, Changes requested, Approved) is stored beside its status. Drafts stay drafts until someone publishes or schedules them, the way WordPress always works.

= How does it use Notes? =

Notes (WordPress 6.9+) are comments on blocks. The plugin counts each post's open notes in the panel and the queue, can require them to be resolved before publishing, and can turn a "changes requested" message into a note on the block it's about. It reads and writes notes exactly as the editor does, so they appear in the Notes sidebar like any other. It never copies them.

= Who can review? =

People with the `ruvelo_review_posts` capability (editors and administrators by default) who can edit the post. Authors can't review their own posts unless you allow it with the `ruvelo_editorial_allow_self_review` filter.

= Can the gate be bypassed? =

Not through WordPress's own paths: the REST API (the block editor), the classic editor, quick and bulk edit, XML-RPC and `wp post update` all go through the check. Administrators can publish past it with a reason, which is logged. Code that calls `wp_publish_post()` directly skips WordPress's save routine; that publish is still recorded in the post's history as unapproved.

= What about posts that are already published? =

The gate applies when a post is first published or scheduled. Updates to published posts aren't held.

= When are emails sent? =

Never during a page load. Each event is queued, and WP-Cron sends each person one email a few minutes later with everything that happened meanwhile. Change the window in Settings, and turn emails off for everyone there or for yourself on your profile. `wp ruvelo-editorial notify` sends what's waiting.

= Does it work with Ruvelo Inbox? =

Yes. When an inbox is active, every review event is also delivered there through the `ruvelo_inbox_notify` action.

= Can AI agents use it? =

Yes, through the WordPress Abilities API: they can read a post's review state and the review queue, ask for a review, and, as an assigned reviewer, approve or request changes. With the MCP adapter installed, the abilities are available to MCP clients too.

= Does it work with the classic editor? =

The Review panel is for the block editor. Every action is also on the review screen (Reviews → Review queue → a post), which works with any editor and without JavaScript.

== Screenshots ==

1. The review queue: what's waiting for you, with approvals, reviewers, open notes and due dates.
2. The Review panel in the editor: state, reviewers and their decisions, approvals, open notes and the gate.
3. Requesting changes, with the option to leave the message as a note on the selected block.
4. The publish gate: Publish waits, with the reason, and administrators can publish anyway with a logged reason.
5. A post's review: the change request, the gate, asking again, and the full history.
6. Asking for a review: reviewers, a due date and a message.
7. The Review column and state filter on the Posts screen.
8. The Reviews dashboard widget.
9. Settings: reviews, gates and approvals per post type, notifications, and uninstalling.
10. The review queue in dark mode.
11. On a phone.

== Changelog ==

= 1.0.0 - 2026-10-09 =

* First release: review states and history, reviewers and due dates, approvals and change requests, publish gates with logged overrides, the review queue, dashboard widget and editor panel, grouped emails and Ruvelo Inbox delivery, REST, WP-CLI and abilities.

== Upgrade Notice ==

= 1.0.0 =

First release.
