<?php

namespace App\Enums;

enum FunctionalCheckType: string
{
    case CONTENT = 'content';
    case REDIRECT = 'redirect';
    case SITEMAP = 'sitemap';
    case ROBOTS_TXT = 'robots_txt';
}
