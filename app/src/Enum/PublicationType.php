<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationType: string
{
    case BOOK = 'BOOK';
    case COMIC = 'COMIC';
    case ARTICLE = 'ARTICLE';
    case DOCUMENT = 'DOCUMENT';
    case WEB = 'WEB';
    case OTHER = 'OTHER';
}
