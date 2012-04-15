=== Charity Data Display ===
Contributors: drkane
Donate link: http://www.drkane.co.uk/projects/charity-data-display/
Tags: open data, data, api, charities
Requires at least: 3.0.0
Tested up to: 3.2.1
Stable tag: trunk

Allows information about a charity to be displayed on any Wordpress page, either in the post or as a widget, based on a pre-defined Charity Number, using a shortcode or based on a custom field. Uses data from [Open Charities](http://opencharities.org/) - a new project to open up the UK charities register.

== Description ==

This wordpress plugin uses data from Open Charities, an open version of the Charity Commission's Register of Charities. Data from Open Charities is used to construct boxes showing information about a particular charity. What information is displayed can be controlled using templates.

You can use Charity Data Display to add information about your own charity on your website, or to allow visitors to check out information about other charities. For example, a foundation could display information about the charities that they fund.

The plugin functionality extends across three key areas:

= Widget =

A widget is created which can either show information about a particular charity or about a charity based on the custom field of the page it is on.

= Shortcode =

A text shortcode can be inserted into pages and post which adds a box of charity data to the page. Again, this can either be set to display a single charity, or to find the charity based on a custom field or based on the URL.

= URL Rewriting =

If you want, the plugin can allow the whole charity register to be access through your site, using URL parameters like ?charity=123456. The url will send the query to a page with a charity shortcode on it. If permalinks are enabled, it will allow a pretty URL format to be used, eg: http://charity.org.uk/charity/123456

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Unzip `charitydata.zip` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. The plugin is now ready to go with the default options
1. You can change any of the options in Settings > Charity Data

== Changelog ==

= 0.1 =
* Initial alpha version with basic functionality