<?php

namespace BrunoDeBarros\Puphpeteer;

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Resources\Browser;
use Nesk\Puphpeteer\Resources\ElementHandle;
use Nesk\Puphpeteer\Resources\HTTPResponse;
use Nesk\Rialto\Data\JsFunction;
use RuntimeException;
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

    public function __construct(\Nesk\Puphpeteer\Resources\Page $page)
    {
        $this->page = $page;
        $this->keyboard = $page->keyboard;
    }

    /**
     * @param string $url
     * @return Page
     * @throws Throwable
     */
    public static function getInstance(string $url): Page
    {
        $browser = self::getPuppeteer();
        $page = $browser->newPage();
        $page->setViewport([
            "width" => 1680,
            "height" => 1050,
        ]);
        $page->goto($url, [
            "waitUntil" => "networkidle0",
        ]);

        $instance = new static($page);
        $instance->logRequest();
        return $instance;
    }

    public static function resetPuppeteer()
    {
        self::$puppeteer_browser = null;
    }

    /**
     * @return Browser
     */
    protected static function getPuppeteer(): Browser
    {
        if (self::$puppeteer_browser === null) {
            $puppeteer = new Puppeteer([
                'executable_path' => env('NODE_PATH'),
                'log_browser_console' => true,
            ]);

            # Always run with UI locally, for easier testing/debugging.
            if (env('APP_ENV') == 'local') {
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

    /**
     * @throws Throwable
     */
    public function logRequest()
    {
        $height = $this->getPageHeight();
        $page = $this->page;

        $page->setViewport([
            "width" => 1280,
            "height" => $height,
        ]);

        $basename = time() . "-" . crc32(uniqid("http-"));
        $png_filename = "puppeteer/$basename.png";
        $html_filename = "puppeteer/$basename.html";
        $filepath = storage_path($png_filename);
        $page->screenshot(['path' => $filepath]);

        $filepath = storage_path($html_filename);
        file_put_contents($filepath, $this->getHtml());
    }

    /**
     * @return int
     */
    public function getPageHeight(): int
    {
        return $this->page->evaluate(JsFunction::createWithBody("var body = document.body, html = document.documentElement;
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
            return $this->page->tryCatch->evaluate(JsFunction::createWithBody("return document.documentElement.outerHTML;"));
        } catch (\Nesk\Rialto\Exceptions\Node\Exception $e) {
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
        }
    }

    /**
     * @param string $selector
     * @return \App\ExpandedElementHandle[]
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

    public function waitTillNotExists(string $selector)
    {
        while ($this->exists($selector)) {
            sleep(1);
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

    public function selectOption(string $select_selector, string $option): array
    {
        $buffer = $this->page->evaluate(JsFunction::createWithBody("return Array.from(document.querySelectorAll('$select_selector option')).map(function(item) { return {value: item.value, label: item.innerText} })"));
        $options = [];
        foreach ($buffer as $row) {
            $options[$row["value"]] = $row["label"];
        }

        return $this->page->select($select_selector, $option);
    }
}
