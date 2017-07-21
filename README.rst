**************************
Micropub for Tiny Tiny RSS
**************************

A plugin for the `Tiny Tiny RSS <https://tt-rss.org/>`_ feed reader to post
comments to blog posts via the `Micropub API <https://www.w3.org/TR/micropub/>`_.

After registering an identity in the preferences, a comment form is shown
below each post.
You can comment on the blog post and submit the reply to your blog without
ever leaving TT-RSS.


.. note:: The micropub plugin does currently not work in
          "combined feed display mode".

Screenshot: http://fotos.cweiske.de/screenshots/2017/2017-05-30%20tt-rss%20micropub%20primetime.png

=====
Setup
=====
First install it into your tt-rss instance::

    $ cd /path/to/tt-rss/plugins.local
    $ git clone https://git.cweiske.de/tt-rss-micropub.git micropub

Now enable the "micropub" plugin in the tt-rss preferences.

After reloading the preferences, a new accordion "Micropub" will be available
in the "Preferences" tab.
Click on it, enter your homepage URL in "Add new identity" and click "Authorize".

If at least one identity has been added, posts in tt-rss have a
"Reply to this post" section at the bottom.


=====================
About tt-rss-micropub
=====================

Source code
===========
The source code is available from https://git.cweiske.de/tt-rss-micropub.git
or the `mirror on github`__.

__ https://github.com/cweiske/tt-rss-micropub


License
=======
The plugin is licensed under the `AGPL v3 or later`__.

__ http://www.gnu.org/licenses/agpl.html


Author
======
tt-rss-micropub was written by `Christian Weiske`__.

__ http://cweiske.de/
