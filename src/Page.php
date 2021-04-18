<?php

namespace BrunoDeBarros\Puphpeteer;

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Resources\Browser;
use Nesk\Puphpeteer\Resources\HTTPResponse;
use Nesk\Rialto\Data\JsFunction;
use Nesk\Rialto\Exceptions\Node\Exception;
use Throwable;

/**
 * Class Page
 */
class Page
{
    /**
     * @var Browser
     */
    protected static $puppeteer_browser;

    /**
     * @var \Nesk\Puphpeteer\Resources\Keyboard
     */
    public $keyboard;

    /**
     * @var \Nesk\Puphpeteer\Resources\Page
     */
    public $page;

    /**
     * @var callable|null
     */
    public $request_logger;

    protected function __construct(\Nesk\Puphpeteer\Resources\Page $page, ?callable $request_logger)
    {
        $this->page = $page;
        $this->keyboard = $page->keyboard;
        $this->request_logger = $request_logger;
    }

    /**
     * @param string $url
     * @param bool $is_debug
     * @param string $node_path
     * @param callable|null $request_logger A callable that accepts (string $url, string $png_contents, string $html_contents)
     * @return Page
     */
    public static function create(string $url, bool $is_debug = false, string $node_path = "/usr/bin/node", ?callable $request_logger = null): Page
    {

        $browser = self::getPuppeteer($is_debug, $node_path);
        $page = $browser->newPage();
        $page->setViewport([
            "width" => 1680,
            "height" => 1050,
        ]);
        $page->goto($url, [
            "waitUntil" => "networkidle0",
        ]);

        $instance = new static($page, $request_logger);
        $instance->logRequest();
        return $instance;
    }

    public static function resetPuppeteer()
    {
        self::$puppeteer_browser = null;
    }

    /**
     * @param bool $is_debug
     * @param string $node_path
     * @return Browser
     */
    protected static function getPuppeteer(bool $is_debug, string $node_path): Browser
    {
        if (self::$puppeteer_browser === null) {
            $puppeteer = new Puppeteer([
                'executable_path' => $node_path,
                'log_browser_console' => true,
            ]);

            # Always run with UI locally, for easier testing/debugging.
            if ($is_debug) {
                $options = [
                    'headless' => false,
                    'slowMo' => 10,
                    'args' =>
                        [
                            '--no-sandbox',
                        ],
                ];
            } else {
                $options = [];
            }

            $browser = $puppeteer->launch($options);
            self::$puppeteer_browser = $browser;
        }

        return self::$puppeteer_browser;
    }

    public function logRequest()
    {
        if ($this->request_logger) {
            $height = $this->getPageHeight();
            $page = $this->page;

            $page->setViewport([
                "width" => 1280,
                "height" => $height,
            ]);

            $basename = time() . "-" . crc32(uniqid("http-"));
            $png_filename = "$basename.png";
            $filepath = sys_get_temp_dir() . "/" . $png_filename;
            $page->screenshot(['path' => $filepath]);
            $png_contents = file_get_contents($filepath);
            unlink($filepath);
            $html_contents = $this->getHtml();
            call_user_func_array($this->request_logger, [$page->url(), $png_contents, $html_contents]);
        }
    }

    /**
     * @return int
     */
    public function getPageHeight(): int
    {
        return $this->page->evaluate(JsFunction::createWithBody(/** @lang JavaScript */ "var body = document.body, html = document.documentElement;
return Math.max(body.scrollHeight, body.offsetHeight, html.clientHeight, html.scrollHeight, html.offsetHeight);"));
    }

    /**
     * Get the HTML source of the currently-loaded page.
     *
     * @return string
     */
    public function getHtml(): ?string
    {
        try {
            return $this->page->tryCatch->evaluate(JsFunction::createWithBody(/** @lang JavaScript */ "return document.documentElement.outerHTML;"));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check whether there's a match for a selector.
     *
     * @param string $selector
     * @return bool
     */
    public function exists(string $selector): bool
    {
        # Handle non-existing elements.
        return !empty($this->page->querySelectorAll($selector));
    }

    public function querySelector(string $selector): ?ExpandedElementHandle
    {
        $element = $this->page->querySelector($selector);
        if ($element) {
            return new ExpandedElementHandle($this, $element);
        } else {
            return null;
        }
    }

    /**
     * @param string $selector
     * @return \BrunoDeBarros\Puphpeteer\ExpandedElementHandle[]
     */
    public function querySelectorAll(string $selector): array
    {
        $elements = $this->page->querySelectorAll($selector);
        foreach ($elements as $key => $value) {
            $elements[$key] = new ExpandedElementHandle($this, $value);
        }
        return $elements;
    }

    public function waitForNavigation(array $options = []): ?HTTPResponse
    {
        return $this->page->waitForNavigation(array_merge([
            "waitUntil" => "networkidle0",
        ], $options));
    }

    public function close(array $options = ["runBeforeUnload" => true]): void
    {
        $this->page->close($options);
    }

    public function waitTillNotExists(string $selector, int $timeout = 30): void
    {
        $seconds_elapsed = 0;
        while ($this->exists($selector) && $seconds_elapsed < $timeout) {
            sleep(1);
            $seconds_elapsed++;
        }
    }

    public function waitForSelector(string $selector, array $options = []): ExpandedElementHandle
    {
        $element = $this->page->tryCatch->waitForSelector($selector, array_merge(["visible" => true], $options));
        return new ExpandedElementHandle($this, $element);
    }

    /**
     * Check if the page contains the given string.
     *
     * @param string $string
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->getHtml(), $string);
    }
}
