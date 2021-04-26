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
     * @var string
     */
    protected static $node_path;

    /**
     * @var string
     */
    protected $download_path;

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
     * @param callable|null $request_logger A callable that accepts (string $url, string $png_contents, string $html_contents)
     * @return static
     */
    public static function create(string $url, bool $is_debug = false, ?callable $request_logger = null): self
    {

        $browser = static::getPuppeteer($is_debug, static::getNodePath());
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

    /**
     * @return string
     */
    public static function getNodePath(): string
    {
        if (static::$node_path === null) {
            $result = trim(shell_exec("which node"));
            if (!empty($result)) {
                static::$node_path = $result;
            }
        }

        return static::$node_path;
    }

    /**
     * @param string $node_path
     */
    public static function setNodePath(string $node_path): void
    {
        static::$node_path = $node_path;
    }

    public function newTab(string $url): self
    {
        $url = parse_url($url);
        $current_url = parse_url($this->page->url());
        $new_url = array_merge($current_url, $url);
        $new_url = (string)Uri::fromParts($new_url);

        $page = static::getPuppeteer()->newPage();
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
    public function evaluate($js)
    {
        if (is_string($js)) {
            $function = (new JsFunction())->body($js);
        } else {
            $function = $js;
        }

        try {
            return $this->page->tryCatch->evaluate($function);
        } catch (Exception $e) {
            throw new PageException($this, $e->getMessage(), $e->getCode(), $e);
        }
    }

    public static function resetPuppeteer()
    {
        if (static::$puppeteer_browser !== null) {
            static::$puppeteer_browser->close();
        }

        static::$puppeteer_browser = null;
    }

    /**
     * @param bool|null $is_debug
     * @param string|null $node_path
     * @return Browser
     */
    protected static function getPuppeteer(?bool $is_debug = null, ?string $node_path = null): Browser
    {
        if (static::$puppeteer_browser === null) {
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
            static::$puppeteer_browser = $browser;
        }

        return static::$puppeteer_browser;
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

    /**
     * Set the path to which files will be downloaded if a download is triggered from the browser.
     *
     * @param string $path
     */
    public function setDownloadPath(string $path): void
    {
        if (!file_exists($path)) {
            throw new \UnexpectedValueException("'$path' does not exist.");
        }

        $path = realpath($path);

        $this->page->_client->send('Page.setDownloadBehavior', [
            "behavior" => 'allow',
            "downloadPath" => $path,
        ]);
        $this->download_path = $path;
    }

    /**
     * Trigger a download in the browser and collect the resulting file (e.g. clicking a JS-powered button).
     *
     * @param callable $trigger_download
     * @return array
     */
    public function triggerDownload(callable $trigger_download): array
    {
        $old_path = $this->getDownloadPath();
        $temp_path = sys_get_temp_dir() . "/" . uniqid("temp-puppeteer");
        mkdir($temp_path);
        echo $temp_path;
        $this->setDownloadPath($temp_path);
        try {
            $trigger_download($this);
            throw new \RuntimeException("This should always abort navigation!");
        } catch (Exception $e) {
            # Wait for the download to end.
            $found_new_file = false;
            $new_files = $this->getFilesList($this->getDownloadPath());

            while (!$found_new_file) {
                $new_files = $this->getFilesList($this->getDownloadPath());

                if (count($new_files) > 0) {
                    $found_new_file = true;
                } else {
                    sleep(1);
                }
            }

            if (count($new_files) > 1) {
                throw new \RuntimeException("More than one new file found! Race condition?");
            }

            $downloaded_file = new \SplFileInfo(reset($new_files));
            $contents = file_get_contents($downloaded_file->getRealPath());
            $basename = $downloaded_file->getBasename();
            $this->setDownloadPath($old_path);
            unlink($downloaded_file->getRealPath());
            rmdir($temp_path);
            return ["filename" => $basename, "contents" => $contents];
        }
    }

    public function getFilesList(string $directory): array
    {
        $directory = new \RecursiveDirectoryIterator($directory);
        $existing = [];
        foreach ($directory as $file) {
            if ($file->isFile()) {
                $existing[] = $file->getRealPath();
            }
        }

        return $existing;
    }

    public function getDownloadPath(): string
    {
        return $this->download_path;
    }

    public function download(string $url): string
    {
        try {
            $current_url = parse_url($this->page->url());
            $download_url = parse_url($url);
            $close_tab = false;

            if ($current_url["host"] != $download_url["host"] || $current_url["scheme"] != $download_url["scheme"]) {
                # Go to the root of the website, because loading a PDF URL causes issues in headless Chrome.
                unset($download_url["path"]);
                $download_url = (string)Uri::fromParts($download_url);
                $tab = $this->newTab($download_url);
                $close_tab = true;
            } else {
                $tab = $this;
            }

            $url = json_encode($url);
            $function = (new JsFunction())->body(/** @lang JavaScript */ "
                return await fetch($url)
                .then((response) => response.blob())
                .then((blob) => {
                    const reader = new FileReader();
                    return new Promise((resolve, reject) => {
                        reader.onloadend = () => {
                          resolve(reader.result);
                        };
                        reader.onerror = () => {
                            reject(reader.error);
                        };
                        reader.readAsDataURL(blob);
                    });
                 });
        ")->async();
            $result = $tab->evaluate($function);
            $binary = file_get_contents($result);

            if ($close_tab) {
                $tab->close();
            }

            return $binary;
        } catch (Exception $e) {
            throw new \RuntimeException("Could not download $url - " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function goto(string $url, array $options = []): ?HTTPResponse
    {
        return $this->page->tryCatch->goto($url, $options);
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

    public function waitForSelector(string $selector): ExpandedElementHandle
    {
        try {
            $element = $this->page->tryCatch->waitForSelector($selector, ["visible" => true]);
            return new ExpandedElementHandle($this, $element);
        } catch (Exception $e) {
            throw new PageException($this, $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function waitForSelectorToDisappear(string $selector, array $options = []): void
    {
        try {
            $this->page->tryCatch->waitForSelector($selector, ["hidden" => true]);
        } catch (Exception $e) {
            throw new PageException($this, $e->getMessage(), $e->getCode(), $e);
        }
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
        $php_script_filename = realpath($php_script_filename);
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
