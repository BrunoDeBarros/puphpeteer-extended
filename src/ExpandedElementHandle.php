<?php

namespace BrunoDeBarros\Puphpeteer;

use Nesk\Puphpeteer\Resources\ElementHandle;
use Nesk\Rialto\Data\JsFunction;
use Nesk\Rialto\Exceptions\Node\Exception;

/**
 * @property-read string $innerHTML
 * @property-read string $innerText
 * @property-read string $value
 * @property-read boolean $checked
 * @property-read string $href
 * @property-read array<string, string> $data
 * @property-read array<string, string> $dataset
 */
class ExpandedElementHandle
{

    /**
     * The current instance of Page.
     *
     * @var \BrunoDeBarros\Puphpeteer\Page
     */
    protected $page;

    /**
     * The current element's handle.
     *
     * @var \Nesk\Puphpeteer\Resources\ElementHandle
     */
    public $element;

    public function __construct(Page $page, ElementHandle $element)
    {
        # Make sure anything that fails doesn't crash the browser.
        $this->element = $element->tryCatch;

        $this->page = $page;
    }

    /**
     * @param string $name
     * @return bool|float|int|string
     */
    public function __get(string $name)
    {
        switch ($name) {
            case "innerHTML":
                return $this->getInnerHTML();
            case "innerText":
                return $this->getInnerText();
            case "value":
                return $this->getValue();
            default:
                return $this->getProperty($name);
        }
    }

    /**
     * Runs a string of JS on the element.
     * The element is passed to the function as `elem`.
     *
     * @param string|JsFunction $js
     * @return mixed
     */
    public function evaluate($js)
    {
        if (is_string($js)) {
            $function = (new JsFunction())->parameters(['elem'])->body($js);
        } else {
            $function = $js;
        }

        try {
            return $this->element->evaluate($function);
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Check whether the element is visible on the page.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        return $this->evaluate(/** @lang JavaScript */ "return window.getComputedStyle(elem).getPropertyValue('display') !== 'none' && elem.offsetHeight;");
    }

    public function blur(): void
    {
        $this->evaluate(/** @lang JavaScript */ "return elem.blur();");
    }

    public function focus(): void
    {
        try {
            $this->element->focus();
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getInnerHTML(): string
    {
        return $this->evaluate(/** @lang JavaScript */ "return elem.innerHTML;");
    }

    protected function getInnerText(): string
    {
        return trim(strip_tags($this->getInnerHTML()));
    }

    /**
     * @param string $property_name
     * @return string|bool|int|float
     */
    protected function getProperty(string $property_name)
    {
        if ($property_name == "data" || $property_name == "dataset") {
            return $this->evaluate(/** @lang JavaScript */ "return JSON.parse(JSON.stringify(elem.dataset))");
        }

        try {
            return $this->element->getProperty($property_name)->jsonValue();
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getValue(): string
    {
        return $this->evaluate(/** @lang JavaScript */ "return elem.value;");
    }

    /**
     * Set the value property of an element.
     *
     * @param string|int|array $value
     * @return static $this
     */
    public function setValue(string|int|array $value): static
    {
        $json = json_encode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('json_encode error: ' . json_last_error_msg(), json_last_error());
        }

        $this->evaluate(/** @lang JavaScript */ "elem.value = $json;");
        return $this;
    }

    /**
     * Types into a text input.
     *
     * @param string $value
     * @param bool $append
     * @param array|null $options
     */
    public function type(string $value, bool $append = false, ?array $options = []): void
    {
        try {
            if (!$append) {
                $this->element->click(["clickCount" => 3]);
            }

            $this->element->type($value, $options);
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }
    
    /**
     * Select file(s) to upload.
     *
     * @param array|string $filepaths
     * @return void
     */
    public function selectFiles(array|string $filepaths): void
    {
        if (!is_array($filepaths)) {
            $filepaths = [$filepaths];
        }

        call_user_func_array([$this->element, "uploadFile"], $filepaths);
    }

    public function click(array $options = []): void
    {
        try {
            if ($this->isVisible()) {
                $this->element->click($options);
            } else {
                $this->evaluate(/** @lang JavaScript */ "return elem.click();");
            }
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function querySelector(string $selector): ?ExpandedElementHandle
    {
        try {
            $element = $this->element->querySelector($selector);
            if ($element) {
                return new ExpandedElementHandle($this->page, $element);
            } else {
                return null;
            }
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Query the element's Shadow DOM.
     *
     * @param string $shadow_selector
     * @return \BrunoDeBarros\Puphpeteer\ExpandedElementHandle|null
     */
    public function querySelectorShadow(string $shadow_selector): ?ExpandedElementHandle
    {
        try {
            $shadow_selector = str_replace("'", "\\'", $shadow_selector);
            $element = $this->element->evaluateHandle((new JsFunction())->parameters(['elem'])->body("return elem.shadowRoot.querySelector('$shadow_selector')"));
            if ($element instanceof ElementHandle) {
                return new ExpandedElementHandle($this->page, $element);
            } else {
                return null;
            }
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string $selector
     * @return \BrunoDeBarros\Puphpeteer\ExpandedElementHandle[]
     */
    public function querySelectorAll(string $selector): array
    {
        try {
            $elements = $this->element->querySelectorAll($selector);
            foreach ($elements as $key => $value) {
                $elements[$key] = new ExpandedElementHandle($this->page, $value);
            }
            return $elements;
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getOptions(): array
    {
        $buffer = $this->evaluate(/** @lang JavaScript */ "return Array.from(elem.querySelectorAll('option')).map(function(item) { return {value: item.value, label: item.innerText} })");
        $valid_options = [];
        foreach ($buffer as $row) {
            $valid_options[$row["value"]] = $row["label"];
        }
        return $valid_options;
    }

    /**
     * Logs the request before clicking an element.
     * The reason for this is to enforce a separation between destructive ("submit") clicks, and harmless/idempotent clicks.
     *
     * @param array $options
     * @throws \Throwable
     */
    public function submit(array $options = []): void
    {
        $this->page->logRequest();
        $this->click($options);
    }

    /**
     * Check if the element's inner HTML contains the given string.
     *
     * @param string $string
     * @return bool
     */
    public function contains(string $string): bool
    {
        return str_contains($this->innerHTML, $string);
    }

    /**
     * @param string $option
     * @return array
     */
    public function selectOption($option): array
    {
        try {
            return $this->element->select($option);
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string|array $options
     * @return array
     */
    public function selectOptions($options): array
    {
        try {
            return $this->element->select($options);
        } catch (Exception $e) {
            throw new PageException($this->page, $e->getMessage(), $e->getCode(), $e);
        }
    }
}
