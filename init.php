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
        static::$myhost = $host;
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
        //command line option --micropub
		$host->add_command(
            'micropub', 'Add Micropub identity', $this, ':', 'MODE'
        );
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

        $accounts = PluginHost::getInstance()->get($this, 'accounts', []);
        if (!count($accounts)) {
            return $article;
        }

        $accountUrls = array_keys($accounts);
        $defaultAccount = null;
        foreach ($accounts as $url => $account) {
            if ($account['default']) {
                $defaultAccount = $url;
            }
        }

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
        if (isset($_REQUEST['accordion'])
            && $_REQUEST['accordion'] == 'micropub'
        ) {
            $accordionActive = 'selected="true"';
        } else {
            $accordionActive = '';
        }

        foreach ($accounts as $url => $account) {
            $accounts[$url]['checked'] = '';
            if ($account['default']) {
                $accounts[$url]['checked'] = 'checked="checked"';
            }
        }

        //FIXME: default identity
        include __DIR__ . '/settings.phtml';
    }

    public function get_prefs_js()
    {
        return file_get_contents(__DIR__ . '/settings.js');
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

    /**
     * HTTP command.
     * Also used by micropub() cli command method.
     *
     * /backend.php?op=pluginhandler&plugin=micropub&method=action
     */
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
        } else if ($mode == 'deleteIdentity') {
            return $this->deleteIdentityAction();
        } else if ($mode == 'setDefaultIdentity') {
            return $this->setDefaultIdentityAction();
        } else {
            return $this->errorOut('Unsupported mode');
        }
    }

    /**
     * Post a comment, like or bookmark via micropub
     */
    protected function postAction()
    {
        $action = 'comment';
        if (isset($_POST['action'])) {
            $action = trim($_POST['action']);
        }
        if (array_search($action, ['bookmark', 'comment', 'like']) === false) {
            return $this->errorOut('"action" parameter invalid');
        }

        if (!isset($_POST['me'])) {
            return $this->errorOut('"me" parameter missing');
        }
        $me = trim($_POST['me']);
        $accounts = PluginHost::getInstance()->get($this, 'accounts', []);
        if (!isset($accounts[$me])) {
            return $this->errorOut('"me" parameter invalid');
        }
        $account = $accounts[$me];

        if (!isset($_POST['postUrl'])) {
            return $this->errorOut('"postUrl" parameter missing');
        }
        $postUrl = trim($_POST['postUrl']);

        if ($action == 'comment') {
            if (!isset($_POST['content'])) {
                return $this->errorOut('"content" parameter missing');
            }
            $content = trim($_POST['content']);
            if (!strlen($_POST['content'])) {
                return $this->errorOut('"content" is empty');
            }
        }


        $links = $this->getLinks($me);
        if (!count($links)) {
            return $this->errorOut('No links found');
        }
        if (!isset($links['micropub'])) {
            return $this->errorOut('No micropub endpoint found');
        }

        $parameters = [
            'access_token' => $account['access_token'],
            'h'            => 'entry',
        ];

        if ($action == 'bookmark') {
            $parameters['bookmark-of'] = $postUrl;

        } else if ($action == 'comment') {
            $parameters['in-reply-to'] = $postUrl;
            $parameters['content']     = $content;

        } else if ($action == 'like') {
            $parameters['like-of'] = $postUrl;
        }


        /* unfortunately fetch_file_contents() does not return headers
           so we have to bring our own way to POST data */
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($parameters),
                'ignore_errors' => true,
            ]
        ];
        $stream = fopen(
            $links['micropub'], 'r', false,
            stream_context_create($opts)
        );
        $meta    = stream_get_meta_data($stream);
        $headers = $meta['wrapper_data'];
        $content = stream_get_contents($stream);

        //we hope there were no redirects and this is actually the only
        // HTTP line in the headers
        $status = array_shift($headers);
        list($httpver, $code, $text) = explode(' ', $status, 3);
        if ($code != 201 && $code != 202) {
            $errData = json_decode($content);
            if (isset($errData->error_description)
                && $errData->error_description != ''
            ) {
                return $this->errorOut(
                    'Error creating post: '
                    . $errData->error_description
                );
            }
            return $this->errorOut(
                'Error creating post: '
                . $code . ' ' . $text.$content
            );
        }

        $location = null;
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) == 2 && strtolower($parts[0]) == 'location') {
                $location = trim($parts[1]);
            }
        }
        if ($location === null) {
            return $this->errorOut(
                'Location header missing in successful creation response.'
            );
        }

        header('Content-type: application/json');
        echo json_encode(
            [
                'code'     => intval($code),
                'location' => $location,
                'message'  => 'Post created',
            ]
        );
        exit();
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
        $accounts = $this->fixDefaultIdentity($accounts);
        $host->set($this, 'accounts', $accounts);

        //all fine now.
        //the accordion parameter will never work
        // because fox has serious mental problems
        // https://discourse.tt-rss.org/t/open-a-certain-accordion-in-preferences-by-url-parameter/234
        header('Location: prefs.php?accordion=micropub');
    }

    /**
     * Backend preferences action: Remove a given account
     */
    protected function deleteIdentityAction()
    {
        if (!isset($_POST['me'])) {
            return $this->errorOut('"me" parameter missing');
        }
        $me = trim($_POST['me']);

        $host = PluginHost::getInstance();
        $accounts = $host->get($this, 'accounts', []);
        if (!isset($accounts[$me])) {
            return $this->errorOut('Unknown identity');
        }

        unset($accounts[$me]);
        $accounts = $this->fixDefaultIdentity($accounts);
        $host->set($this, 'accounts', $accounts);

        header('Content-type: application/json');
        echo json_encode(
            [
                'code'     => '200',
                'message'  => 'Identity removed',
            ]
        );
        exit();
    }

    /**
     * Backend preferences action: Make a given account the default
     */
    protected function setDefaultIdentityAction()
    {
        if (!isset($_POST['me'])) {
            return $this->errorOut('"me" parameter missing');
        }
        $me = trim($_POST['me']);

        $host = PluginHost::getInstance();
        $accounts = $host->get($this, 'accounts', []);
        if (!isset($accounts[$me])) {
            return $this->errorOut('Unknown identity');
        }
        foreach ($accounts as $url => $data) {
            $accounts[$url]['default'] = ($url == $me);
        }
        $host->set($this, 'accounts', $accounts);

        header('Content-type: application/json');
        echo json_encode(
            [
                'code'     => '200',
                'message'  => 'Default account set',
            ]
        );
        exit();
    }

    /**
     * Set the default identity if there is none
     *
     * @param array $accounts Array of account data arrays
     *
     * @return array Array of account data arrays
     */
    protected function fixDefaultIdentity($accounts)
    {
        if (!count($accounts)) {
            return $accounts;
        }

        $hasDefault = false;
        foreach ($accounts as $account) {
            if ($account['default']) {
                $hasDefault = true;
            }
        }

        if (!$hasDefault) {
            reset($accounts);
            $accounts[key($accounts)]['default'] = true;
        }
        return $accounts;
    }

    /**
     * Send an error message.
     * Automatically in the correct format (plain text or json)
     *
     * @param string $msg Error message
     *
     * @return void
     */
    protected function errorOut($msg)
    {
        header('HTTP/1.0 400 Bad Request');

        //this does not take "q"uality values into account, I know.
        if (isset($_SERVER['HTTP_ACCEPT'])
            && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        ) {
            //send json error
            header('Content-type: application/json');
            echo json_encode(
                [
                    'error' => $msg,
                ]
            );
        } else {
            header('Content-type: text/plain');
            echo $msg . "\n";
        }
        exit(1);
    }

    /**
     * Extract link relations from a given URL
     */
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

    /**
     * If a valid CSRF token is necessary or not
     *
     * @param string $method Plugin method name (here: "action")
     *
     * @return boolean True if an invalid CSRF token shall be ignored
     */
    function csrf_ignore($method)
    {
        $mode = null;
        if (isset($_POST['mode'])) {
            $mode = $_POST['mode'];
        } else if (isset($_GET['mode'])) {
            $mode = $_GET['mode'];
        }

        if ($mode == 'authreturn') {
            return true;
        }

        return false;
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
