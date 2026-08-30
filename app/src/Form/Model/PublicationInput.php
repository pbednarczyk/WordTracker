<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Enum\PublicationType;
use Symfony\Component\Validator\Constraints as Assert;

final class PublicationInput
{
    #[Assert\NotBlank(message: 'Enter a publication title.')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot be longer than {{ limit }} characters.')]
    public ?string $title = null;

    #[Assert\Length(max: 255, maxMessage: 'Author cannot be longer than {{ limit }} characters.')]
    public ?string $author = null;

    #[Assert\NotNull(message: 'Choose a publication type.')]
    public ?PublicationType $type = PublicationType::ARTICLE;

    #[Assert\NotBlank(message: 'Enter a language code.')]
    #[Assert\Length(max: 8, maxMessage: 'Language code cannot be longer than {{ limit }} characters.')]
    public ?string $language = 'en';

    #[Assert\NotBlank(message: 'Paste text to analyze.')]
    public ?string $rawText = null;
}
