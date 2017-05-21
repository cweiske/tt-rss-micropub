<?php
/**
 * Simple Micropub client to post reponses
 *
 * PHP version 5
 *
 * @author  Christian Weiske <cweiske@cweiske.de>
 * @license AGPLv3 or later
 * @link    https://www.w3.org/TR/micropub/
 */
class Micropub extends Plugin
{
    public function about()
    {
        return array(
            0.1,
            'Micropub',
            'cweiske',
            false
        );
    }
    
    public function init($host)
    {
        $host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
    }

    function get_css()
    {
        return file_get_contents(__DIR__ . '/init.css');
    }

    /**
     * @param array $article Article data. Keys:
     *                       - id 
     *                       - title 
     *                       - link 
     *                       - content 
     *                       - feed_id 
     *                       - comments 
     *                       - int_id 
     *                       - lang 
     *                       - updated 
     *                       - site_url 
     *                       - feed_title 
     *                       - hide_images 
     *                       - always_display_enclosures 
     *                       - num_comments 
     *                       - author 
     *                       - guid 
     *                       - orig_feed_id 
     *                       - note 
     *                       - tags 
     */
    public function hook_render_article($article)
    {
        $quillUrl = 'https://quill.p3k.io/new'
            . '?reply=' . urlencode($article['link']);
        $hQuillUrl = htmlspecialchars($quillUrl);
        // did I tell you I hate dojo/dijit?
        $article['content'] .= '<div class="reply">'
            . '<a href="' . $hQuillUrl . '" class="mpbutton">'
            . 'Reply with Quill'
            . '</a>'
            . '</div>';
        return $article;
    }

    public function api_version()
    {
        return 2;
    }
}
?>
