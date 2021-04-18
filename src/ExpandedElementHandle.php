<?php

namespace BrunoDeBarros\Puphpeteer;

use Nesk\Puphpeteer\Resources\ElementHandle;
use Nesk\Rialto\Data\JsFunction;

/**
 * @property-read string $innerHTML
 * @property-read string $innerText
 * @property-read string $value
 * @property-read boolean $checked
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
    protected $element;

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

    protected function getFunction(): JsFunction
    {
        return (new JsFunction())->parameters(['elem']);
    }

    /**
     * Check whether the element is visible on the page.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        $function = $this->getFunction()->body(/** @lang JavaScript */ "return window.getComputedStyle(elem).getPropertyValue('display') !== 'none' && elem.offsetHeight;");
        return $this->element->evaluate($function);
    }

    public function blur(): void
    {
        $function = $this->getFunction()->body(/** @lang JavaScript */ "return elem.blur();");
        $this->element->evaluate($function);
    }

    public function focus(): void
    {
        $this->element->focus();
    }

    protected function getInnerHTML(): string
    {
        $function = $this->getFunction()->body(/** @lang JavaScript */ "return elem.innerHTML;");
        return $this->element->evaluate($function);
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
        return $this->element->getProperty($property_name)->jsonValue();
    }

    protected function getValue(): string
    {
        $function = $this->getFunction()->body(/** @lang JavaScript */ "return elem.value;");
        return $this->element->evaluate($function);
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
        if (!$append) {
            $this->element->click(["clickCount" => 3]);
        }

        $this->element->type($value, $options);
    }

    public function click(array $options = []): void
    {
        if ($this->isVisible()) {
            $this->element->click($options);
        } else {
            $function = $this->getFunction()->body(/** @lang JavaScript */ "return elem.click();");
            $this->element->evaluate($function);
        }
    }

    public function querySelector(string $selector): ?ExpandedElementHandle
    {
        $element = $this->element->querySelector($selector);
        if ($element) {
            return new ExpandedElementHandle($this->page, $element);
        } else {
            return null;
        }
    }

    public function querySelectorAll(string $selector): array
    {
        $elements = $this->element->querySelectorAll($selector);
        foreach ($elements as $key => $value) {
            $elements[$key] = new ExpandedElementHandle($this->page, $value);
        }
        return $elements;
    }

    public function getOptions(): array
    {
        $function = $this->getFunction()->body(/** @lang JavaScript */ "return Array.from(elem.querySelectorAll('option')).map(function(item) { return {value: item.value, label: item.innerText} })");
        $buffer = $this->element->evaluate($function);
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
        return $this->element->select($option);
    }

    /**
     * @param string|array $options
     * @return array
     */
    public function selectOptions($options): array
    {
        return $this->element->select($options);
    }
}
