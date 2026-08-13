<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\MarkdownRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly MarkdownRenderer $markdownRenderer)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown_to_html', $this->markdownRenderer->render(...), ['is_safe' => ['html']]),
        ];
    }
}
