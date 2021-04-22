<?php

namespace BrunoDeBarros\Puphpeteer;

use Throwable;

class PageException extends \RuntimeException
{

    /**
     * @var \BrunoDeBarros\Puphpeteer\Page
     */
    protected $page;

    function __construct(Page $page, $message = "", $code = 0, Throwable $previous = null)
    {
        $this->page = $page;
        parent::__construct($message, $code, $previous);
    }

    function debug()
    {
        $php_script_filename = tempnam(sys_get_temp_dir(), "debug-") . ".php";
        dump("An error occurred: $this->message");
        dump($this->getTraceAsString());
        dump("We're launching debug mode. You can send commands to this browser tab by editing the PHP file below.");
        dump("You can use \$this in the file (which is the current instance of " . Page::class . ").");
        $this->page->awaitCommands($php_script_filename);
    }

    /**
     * @return \BrunoDeBarros\Puphpeteer\Page
     */
    public function getPage(): Page
    {
        return $this->page;
    }

}