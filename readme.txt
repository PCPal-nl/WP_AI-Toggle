=== AI Toggle ===
Contributors: yourwporgusername
Tags: ai, categories, filter, toggle, menu
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A menu switch that lets visitors hide the posts of one chosen category from the feed, archives and search results.

== Description ==

AI Toggle adds a switch behind the items of a menu location you choose. When a
visitor turns the switch on, every post in the category you configured
disappears from the blog page, the archives and the search results. The choice
is stored in a cookie and applies to the pages the visitor opens afterwards.

The plugin was written to give readers a way to opt out of AI-written articles
on a blog that publishes both, but the category is entirely up to you. Anything
you can put in a category can be switched off this way: sponsored posts, link
roundups, a podcast feed, a series a returning reader has already finished.

**Filtering happens server-side**

The category is excluded in `pre_get_posts`, on the main query only. Nothing is
hidden with CSS. That matters: a CSS solution leaves holes in the list and makes
pagination, `max_num_pages` and any load-more or infinite scroll disagree with
what is actually on screen. Because this plugin filters the query itself, the
page count and the "next page" links stay correct.

**It filters, it does not block**

Single posts remain reachable through a direct link. The archive of the hidden
category itself is never emptied out, because a visitor who navigates there is
asking for those posts on purpose. Feeds are left alone as well.

**No JavaScript**

The switch is a small form that posts and redirects back to the same page. It
works with JavaScript disabled, it cannot get out of step with what the server
rendered, and it adds no scripts to your front end.

**Caching**

When the plugin is active it sends a `Vary: Cookie` header on list views and
defines `DONOTCACHEPAGE` for visitors who have the switch turned on, so a page
cache does not serve the filtered page to everyone.

**Privacy**

The plugin sets one first-party cookie, named `ai_toggle`, containing `1` or
`0`. It makes no external requests, loads nothing from a third party, collects
no statistics and adds no "powered by" link.

== Installation ==

1. Upload the `ai-toggle` folder to `wp-content/plugins/`, or install the plugin
   through the WordPress plugin screen.
2. Activate the plugin through the *Plugins* screen.
3. Go to *Settings > AI Toggle*, pick the category that should be hideable and
   tick the menu location(s) the switch should appear in.

That is all the configuration there is. Without a category the plugin stays
inactive and renders nothing.

== Frequently Asked Questions ==

= Can I place the switch outside a menu? =

Yes. Use the `[ai_toggle]` shortcode in a post, a page or a shortcode-capable
block or widget. Leaving every menu location unticked makes the shortcode the
only place the switch appears.

= Can visitors hide more than one category? =

No. The plugin handles one category and one switch. That is a deliberate choice:
the point is a single, obvious control in the menu rather than a filter panel.

= Does the switch hide posts from everyone? =

No. The setting is per visitor and lives in that visitor's own browser cookie.
Nothing is hidden for anyone else, and nothing is unpublished.

= What happens if I delete the category? =

The switch disappears from the front end and the settings page shows a notice
telling you the configured category no longer exists. The site keeps working.

= Does it work with a page cache or a CDN? =

The plugin sends `Vary: Cookie` on list views and defines `DONOTCACHEPAGE` for
visitors who switched the category off. Most page caches respect at least one of
those. If your cache ignores both, it will serve one cached version to everyone,
which is a limitation of the cache, not of this plugin.

= Does it affect the admin, feeds or single posts? =

No. Filtering is limited to the main query of the blog page, archives and search
results on the front end.

= In which languages is it available? =

English and Dutch are bundled. The plugin is fully translatable through the
`ai-toggle` text domain.

== Screenshots ==

1. The switch in a theme's primary menu, turned off.
2. The same menu with the switch turned on; the posts in the chosen category are
   gone from the feed below it.
3. The settings screen under Settings > AI Toggle.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
