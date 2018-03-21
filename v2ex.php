<?php
require dirname(__FILE__) . '/vendor/autoload.php';
use Shooyaaa\Three\Utils\Curl;
use Shooyaaa\Three\Utils\Helper;
use Shooyaaa\Three\Contracts\Url;
use Symfony\Component\DomCrawler\Crawler;

class V2ex {
    private $_http;
    private $_host = "http://www.v2ex.com";

    private $_posts;
    private $_nodes;
    private $_currentNode;

    public function __construct(Url $u) {
        $this->_http = $u;
    }

    public function loginUrl() {
        return $this->_config[$this->_version]['host'] . $this->_config[$this->_version]['loginUrl'];
    }

    public function login() {
        $loginUrl = $this->loginUrl();
        $content = $this->_http->get($loginUrl)->getContent()->withCookie()->redirect()->go();
        $crawler = new Crawler($content, $loginUrl);

        $form = $crawler->filter('.form-horizontal')->form();

        $uri = $form->getUri();
        $method = $form->getMethod();

        $values = $form->getValues();
        $values['account'] = $this->_config[$this->_version]['account']['user'];
        $values['password'] = $this->_config[$this->_version]['account']['password'];
        $values['submit'] = 1;

        $content = $this->_http->post($uri, $values)->getContent()->withCookie()->redirect()->go();
    }

    public function listPost() {
        $url = $this->_host;
        if (!empty($this->_currentNode)) {
            $url .= '?tab=' . $this->_currentNode['href'];
        }
        $content = $this->_http->get($url)->getContent()->withCookie()->redirect()->go();

        $crawler = new Crawler($content);

        $div = $crawler->filterXPath('//*[@class="cell item"]');

        $all = 0;

        $div->each(function ($node, $i) use (&$all){
            $class = $node->attr('class');
            $td = $node->filterXPath('//table/tr/td');
            $comments = 0;
            $title = "";
            $href = "";
            $td->each(function ($node, $j) use (&$title, &$href, &$comments) {
                if ($j == 3) {
                    $comments = trim($node->text());
                } else if ($j == 2) {
                    $a = $node->filterXPath('//*[@class="item_title"]/a');
                    $href = $a->attr('href');
                    $title = $a->text();
                }
            });
            $this->_posts[] = ['title' => $title, 'href' => $href, 'comments' => $comments];
        });

        $links = $crawler->filterXPath('//*[@class="box"]/*[@class="inner"]/a');
        $links->each(function ($a, $i) {
            $href = $a->attr('href');
            $text= $a->text();
            if ($text == "") {
                return;
            }
            $class = $a->attr('class');
            if ($class == 'tab_current') {
                $this->_currentNode = $text;
            }
            $this->_nodes[] = ['href' => $href, 'name' => $text];
        });
    }

    public function prompt() {
        $line = readline('>>>');
        $this->parseCommand($line);
    }

    public function parseCommand($cmd) {
        if ($cmd == 'exit') {
            exit;
        }elseif (is_numeric($cmd)) {
            $this->switchNode($cmd);
        } else if (preg_match('/[a-z]{1,3}/', $cmd)) {
            $this->postDetail($cmd);
        }
        $this->prompt();
    }

    public function switchNode($cmd) {
        if (empty($this->_nodes[$cmd])) {
            echo 'unknown node ' . $cmd;
            return;
        }
        $this->_currentNode = $this->_nodes[$cmd];
        $this->outputNodeAndPost();
    }

    public function postDetail($postId) {
        $index = Helper::charsToNumber($postId);
        if (empty($this->_posts[$index])) {
            echo "unknown post id\r\n";
            return;
        }
        $post = $this->_posts[$index];
        $postUrl = $this->_host . $post['href'] . '#reply' . $post['comments'];
        echo $postUrl, "\r\n";
        $content = $this->_http->get($postUrl)->getContent()->go();
        $crawler = new Crawler($content);
        $box = $crawler->filterXPath('//*[@id="Main"]/*[@class="box"]');
        $box->each(function ($node, $i) use ($crawler) {
            if ($i == 0) {
                $header = $crawler->filterXPath('//h1')->text();
                $topic = $crawler->filterXPath('//*[@class="topic_content"]');
                echo "Title " . $header, "\r\n";
                echo $topic->text(), "\r\n";
            } else if ($i == 1) {
                $node->filterXPath("//div")->each(function ($div, $i) {
                    try {
                        $author = $div->filterXPath('//*[@class="dark"]')->text();
                        $text = $div->filterXPath('//*[@class="reply_content"]')->text();
                        echo " <<<[$author]\r\n     " . $this->formatLines($text), "\r\n\r\n";
                    } catch (\InvalidArgumentException $e) {

                    }
                });
            }
        });
    }

    public function printAllNode() {
        if (empty($this->nodes)) {
            $this->listPost();
        }

        foreach ($this->_nodes as $i => $node) {
            $name = $node['name'];
            echo "($i)$name ";
        }
        echo "\r\n";
    }

    public function printAllPost() {
        if (empty($this->_nodes)) {
            $this->listPost(); }

        foreach ($this->_posts as $i => $node) {
            $title = Helper::abbrevText($node['title'], 48, "...");
            $char = Helper::numberToChars($i);
            $comments = $node['comments'];
            $text = " ($char) {$title}";
            if ($comments) {
                $text .= "@$comments";
            }
            $text .= "\r\n";
            echo $text;
        }
    }

    public function tip() {
        echo "Input link to view post or switch node  \r\n";
    }

    public function help() {
        echo "V2ex for shell\r\n";
        echo "\t nodes\r\n";
        $this->outputNodeAndPost();
    }

    public function outputNodeAndPost() {
        $this->printAllNode();
        $this->printAllPost();
        $this->tip();
        $this->prompt();
    }

    public function styleEcho($buffer) {
        exec('echo -e ' . $buffer);
    }

    public function withColor($buffer, $color) {
        return $this;
    }

    public function formatLines($text) {
        $cols = 111;//Helper::terminalCols() - 6;
        $fragments = Helper::mbStrFragment($text, $cols);
        return implode("\r\n   ", $fragments);
    }

}

$http = new Curl();
$e = new V2ex($http);
$e->help();
