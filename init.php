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
class Micropub extends Plugin implements IHandler
{
    /**
     * Dumb workaround for "private $host" in Plugin class
     * + the re-creation of the plugin instance without calling init().
     *
     * @var PluginHost
     * @see https://discourse.tt-rss.org/t//208
     * @see https://discourse.tt-rss.org/t//209
     */
    protected static $myhost;

    public function __construct()
    {
        //do nothing. only here to not have micropub() called as constructor
    }

    public function about()
    {
        return [
            0.1,
            'Micropub',
            'cweiske',
            false
        ];
    }

    public function api_version()
    {
        return 2;
    }

    /**
     * Register our hooks
     */
    public function init(/*PluginHost*/ $host)
    {
        parent::init($host);
        static::$myhost = $host;
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
        //command line option --micropub
		$host->add_command(
            'micropub', 'Add Micropub identity', $this, ':', 'MODE'
        );
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
        // did I tell you I hate dojo/dijit?

        $accounts = array_keys(PluginHost::getInstance()->get($this, 'accounts', []));

        ob_start();
        include __DIR__ . '/commentform.phtml';
        $html = ob_get_clean();
        $article['content'] .= $html;

        return $article;
    }

    /**
     * Render our configuration page.
     * Directly echo it out.
     *
     * @param string $args Preferences tab that is currently open
     *
     * @return void
     */
    public function hook_prefs_tab($args)
    {
        if ($args != "prefPrefs") {
            return;
        }

        $accounts = PluginHost::getInstance()->get($this, 'accounts', []);

        include __DIR__ . '/settings.phtml';
    }

    /**
     * CLI command
     */
    public function micropub($args)
    {
        //we do not get all arguments passed here, to we work around
        $args = $GLOBALS['argv'];
        array_shift($args);//update.php
        array_shift($args);//--micropub
        $mode = array_shift($args);
        return $this->action($mode, $args);
    }

    public function action($mode = null, $args = [])
    {
        if (isset($_POST['mode'])) {
            $mode = $_POST['mode'];
        } else if (isset($_GET['mode'])) {
            $mode = $_GET['mode'];
        }

        if ($mode == 'authorize') {
            return $this->authorizeAction($args);
        } else if ($mode == 'authreturn') {
            return $this->authreturnAction();
        } else if ($mode == 'post') {
            return $this->postAction();
        } else {
            $this->errorOut('Unsupported mode');
        }
    }

    protected function postAction()
    {
        if (!isset($_POST['me'])) {
            return $this->errorOut('"me" parameter missing');
        }
        $me = $_POST['me'];

        if (!isset($_POST['replyTo'])) {
            return $this->errorOut('"replyTo" parameter missing');
        }
        $replyTo = $_POST['replyTo'];

        if (!isset($_POST['content'])) {
            return $this->errorOut('"content" parameter missing');
        }
        $content = $_POST['content'];

        $accounts = PluginHost::getInstance()->get($this, 'accounts', []);
        if (!isset($accounts[$me])) {
            return $this->errorOut('"me" parameter invalid');
        }
        $account = $accounts[$me];

        $links = $this->getLinks($me);
        if (!count($links)) {
            return $this->errorOut('No links found');
        }
        if (!isset($links['micropub'])) {
            return $this->errorOut('No micropub endpoint found');
        }

        $res = fetch_file_contents(
            [
                //FIXME: add content-type header once this is fixed:
                // https://discourse.tt-rss.org/t//207
                'url'        => $links['micropub'],
                //we use http_build_query to force cURL
                // to use x-www-form-urlencoded
                'post_query' => http_build_query(
                    [
                        'access_token' => $account['access_token'],
                        'h'            => 'entry',
                        'in-reply-to'  => $replyTo,
                        'content'      => $content,
                    ]
                ),
                'followlocation' => false,
            ]
        );

        if ($GLOBALS['fetch_last_error_code'] == 201) {
            //FIXME: extract location header
            echo "OK, comment post created\n";
        } else {
            $this->errorOut(
                'An error occured: '
                . $GLOBALS['fetch_last_error_code']
                . ' ' . $GLOBALS['fetch_last_error_code_content']
            );
        }
    }

    protected function authorizeAction($args = [])
    {
        if (count($args)) {
            $url = array_shift($args);
        } else if (isset($_POST['url'])) {
            $url = $_POST['url'];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->errorOut('Invalid URL');
        }

        //step 1: micropub discovery
        $links = $this->getLinks($url);

        if (!count($links)) {
            return $this->errorOut('No links found');
        }
        if (!isset($links['micropub'])) {
            return $this->errorOut('No micropub endpoint found');
        }
        if (!isset($links['token_endpoint'])) {
            return $this->errorOut('No token endpoint found');
        }
        if (!isset($links['authorization_endpoint'])) {
            return $this->errorOut('No authorization endpoint found');
        }

        $redirUrl = get_self_url_prefix() . '/backend.php'
            . '?op=micropub&method=action&mode=authreturn';
        $authUrl = $links['authorization_endpoint']
            . '?me=' . $url
            . '&redirect_uri=' . urlencode($redirUrl)
            . '&client_id=' . urlencode(get_self_url_prefix())//FIXME: app info
            //. '&state=' . 'FIXME'
            . '&scope=create'
            . '&response_type=code';
        header('Location: ' . $authUrl);
        echo $authUrl . "\n";
        exit();
    }

    /**
     * Return from authorization
     */
    public function authreturnAction()
    {
        if (!isset($_GET['me'])) {
            return $this->errorOut('"me" parameter missing');
        }
        if (!isset($_GET['code'])) {
            return $this->errorOut('"code" parameter missing');
        }

        $links = $this->getLinks($_GET['me']);
        if (!isset($links['token_endpoint'])) {
            return $this->errorOut('No token endpoint found');
        }

        //obtain access token from the code
        $redirUrl = get_self_url_prefix() . '/backend.php'
            . '?op=micropub&method=action&mode=authreturn';
        $res = fetch_file_contents(
            [
                //FIXME: add accept header once this is fixed:
                // https://discourse.tt-rss.org/t//207
                'url'        => $links['token_endpoint'],
                'post_query' => [
                    'grant_type'   => 'authorization_code',
                    'me'           => $_GET['me'],
                    'code'         => $_GET['code'],
                    'redirect_uri' => $redirUrl,
                    'client_id'    => get_self_url_prefix()
                ]
            ]
        );

        //we have no way to get the content type :/
        if ($res{0} == '{') {
            //json
            $data = json_decode($res);
        } else {
            parse_str($res, $data);
        }
        if (!isset($data['access_token'])) {
            return $this->errorOut('access token missing');
        }
        if (!isset($data['me'])) {
            return $this->errorOut('access token missing');
        }
        if (!isset($data['scope'])) {
            return $this->errorOut('scope token missing');
        }

        $host = PluginHost::getInstance();
        $accounts = $host->get($this, 'accounts', []);
        $accounts[$data['me']] = [
            'access_token' => $data['access_token'],
            'scope'        => $data['scope'],
        ];
        $host->set($this, 'accounts', $accounts);

        //all fine now.
        header('Location: prefs.php');
    }

    protected function errorOut($msg)
    {
        echo $msg . "\n";
        exit(1);
    }

    protected function getLinks($url)
    {
        //FIXME: HTTP Link header support with HTTP2
        $html = fetch_file_contents(
            [
                'url' => $url,
            ]
        );
        //Loading invalid HTML is tedious.
        // quick hack with regex. yay!
        preg_match_all('#<link[^>]+?>#', $html, $matches);
        $links = [];
        foreach ($matches[0] as $match) {
            if (substr($match, -2) != '/>') {
                //make it valid xml...
                $match = substr($match, 0, -1) . '/>';
            }
            $sx = simplexml_load_string($match);
            if (isset($sx['rel']) && isset($sx['href'])
                && !isset($links[(string) $sx['rel']])
            ) {
                $links[(string) $sx['rel']] = (string) $sx['href'];
            }
        }
        return $links;
    }

    function csrf_ignore($method)
    {
        return true;
    }

    /**
     * Check which method is allowed via HTTP
     */
    function before($method)
    {
        if ($method == 'action') {
            return true;
        }
        return false;
    }

    function after()
    {
        return true;
    }
}
?>
