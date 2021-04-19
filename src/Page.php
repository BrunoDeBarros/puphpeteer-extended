<?php

namespace BrunoDeBarros\Puphpeteer;

use GuzzleHttp\Psr7\Uri;
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

    public function newTab(string $url): self
    {
        $url = parse_url($url);
        $current_url = parse_url($this->page->url());
        $new_url = array_merge($current_url, $url);
        $new_url = (string)Uri::fromParts($new_url);

        $page = self::getPuppeteer()->newPage();
        $page->goto($new_url, [
            "waitUntil" => "networkidle0",
        ]);
        $instance = new static($page, $this->request_logger);
        $instance->logRequest();
        return $instance;
    }

    /**
     * Runs a string of JS on the document.
     *
     * @param string|JsFunction $js
     * @return mixed
     */
    public function evaluate(string|JsFunction $js)
    {
        if (is_string($js)) {
            $function = (new JsFunction())->body($js);
        } else {
            $function = $js;
        }

        return $this->page->tryCatch->evaluate($function);
    }

    public static function resetPuppeteer()
    {
        self::$puppeteer_browser = null;
    }

    /**
     * @param bool|null $is_debug
     * @param string|null $node_path
     * @return Browser
     */
    protected static function getPuppeteer(?bool $is_debug = null, ?string $node_path = null): Browser
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
        return $this->evaluate(/** @lang JavaScript */ "var body = document.body, html = document.documentElement;
return Math.max(body.scrollHeight, body.offsetHeight, html.clientHeight, html.scrollHeight, html.offsetHeight);");
    }

    /**
     * Get the HTML source of the currently-loaded page.
     *
     * @return string
     */
    public function getHtml(): ?string
    {
        try {
            return $this->evaluate(/** @lang JavaScript */ "return document.documentElement.outerHTML;");
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

    public function download(string $url): string
    {
        $url = json_encode($url);
        $function = (new JsFunction())->body(/** @lang JavaScript */ "
            return await fetch($url)
            .then(response => response.blob())
             .then((blob) => {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                        reader.addEventListener('loadend', () => {
                           resolve(reader.result);
                        });
                    reader.readAsDataURL(blob);
                });
             });
        ")->async();
        $result = $this->evaluate($function);
        $binary = file_get_contents($result);
        return $binary;
    }

    public function goto(string $url, array $options = []): ?HTTPResponse
    {
        return $this->page->goto($url, $options);
    }

    public function reload(array $options = []): ?HTTPResponse
    {
        return $this->page->reload($options);
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

    /**
     * Run arbitrary PHP during testing by modifying $php_script_filename.
     * It will keep the browser alive and await changes to the file.
     *
     * @param string $php_script_filename
     */
    public function awaitCommands(string $php_script_filename): void
    {
        $last_contents = file_get_contents($php_script_filename);
        dump("Awaiting PHP commands at $php_script_filename.");
        while (true) {
            $new_contents = file_get_contents($php_script_filename);
            if ($new_contents != $last_contents) {
                $last_contents = $new_contents;
                dump("Detected change to $php_script_filename, running...");
                try {
                    require $php_script_filename;
                } catch (Throwable $e) {
                    dump("Found a " . get_class($e) . ": " . $e->getMessage());
                }
            }

            # Keep the browser alive.
            $this->getHtml();

            sleep(1);
        }
    }
}
