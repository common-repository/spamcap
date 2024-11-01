=== Stop Spam Wordpress Plugin ===
Contributors: Jason Jones
Tags: anti-spam, captcha, comments, spam, bot, robot, human, reCAPTCHA, Akismet, TypePad AntiSpam
Requires at least: 3.0
Tested up to: 8.0
Stable tag: trunk

Asks commenters to complete a simple CAPTCHA if a spam detection plugin thinks their comment is spam. Currently supports Akismet and TypePad AntiSpam.

== Description ==

Services like Akismet are great at detecting spam, but if you get lots of it then you have to trawl through the spam queue in case there are any false positives.

This plugin provides a CAPTCHA complement to these spam detection plugins:

* If a spam detection plugin identifies a comment as spam, it will ask the commenter to complete a simple CAPTCHA.
* If they fail, then the comment will be automatically discarded or trashed (and won't clutter up your spam queue). 
* If they pass, it will be allowed into the spam queue (or approved, if you so choose).
* Meanwhile, genuine commenters (i.e., those not flagged by Akismet) will be able to comment on your site hassle-free.

The default CAPTCHA is a simple text-based test. There is also the option to use [reCAPTCHA](http://recaptcha.net) if you want something more robust (note: this requires getting a free API key). You can also style the CAPTCHA page to fit with your own Wordpress theme.

**Requirements:**

* *Spamcap* currently supports [Akismet](http://akismet.com) and [TypePad AntiSpam](http://antispam.typepad.com). You must have one of these plugins installed and active in order for this plugin to work.
* PHP version 5 or greater

== Upgrade Notice ==

= 8.0 =
Minor modifications in response to changes in the latest version of Akismet.

= 7.0 =
Fixes a bug on the settings page that prevented some users from using the reCAPTCHA option.

= 6.0 =
Adds more flexibility to CAPTCHA pass/fail handling, and fixes a bug in the plugin's upgrade routine.

== Changelog ==

= 8.0 =
Minor modifications in response to changes in the latest version of Akismet.

= 7.0 =
Fixes a bug on the settings page that prevented some users from using the reCAPTCHA option.

= 6.0 =
Adds more flexibility to CAPTCHA pass/fail handling, and fixes a bug in the plugin's upgrade routine.

= 5.0 =
* Minor changes to the behaviour of the plugin, as a result of changes in the latest version of Akismet.

= 4.0 =
* Bugfix: settings page Javascript caused errors when using jQuery < 1.6

= 3.5 =
* Added the option to leave comments for unsuccessful CAPTCHAs in the spam queue (provided the pass action something different)
* Bugfix: Options from previous versions of the plugin were not being properly upgraded

= 3.1.1 =
* Bugfix: Admin page Javascript was not compatible with jQuery >1.5.2

= 3.1 =
* Bugfix: Use blog character set instead of defaulting to UTF-8
* Better preview of CAPTCHA page
* Added basic validation of reCAPTCHA API keys
* Minor usability improvements

= 3.0 =
* Bugfix: don't mangle Unicode characters when submitting a CAPTCHA. Thanks to Mantas for pointing this out.

= 2.9 =
* Updated to fix issue with Akismet version 2.5.0 and Wordpress 3.0.3 when set to trash failed comments

= 2.8 =
* Added the ability to customise the appearance and language of reCAPTCHA

= 2.7 =
* Ensure that passed CAPTCHAs are reported as false positives to Akismet/TypePad Antispam. Thanks to [Kevin](http://www.investitwisely.com) for the suggestion.
* Added the option to place passed comments in the moderation queue

= 2.6 =
* Added support for non-js reCAPTCHA
* Updated reCAPTCHA API interface
* Modified upgrade routine because of changes to plugin update handling in Wordpress 3.1

= 2.5 =
* Added the ability to preview the CAPTCHA page from within the administration interface
* Minor performance optimisations
* Raised minimum Wordpress version to 2.8

= 2.4 =
* Bugfix: don't intercept spammy pingbacks and trackbacks. 

== Installation ==

1. Upload the spamcap folder to the `/wp-content/plugins/` directory (or use the Wordpress auto-install feature)
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The settings for the plugin can be accessed from the Plugins administration menu.
