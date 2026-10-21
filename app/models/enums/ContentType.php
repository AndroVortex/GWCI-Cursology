<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** course_chapter_content.content_type — ck_content_type. */
enum ContentType: string
{
    case Video = 'video';
    case Document = 'document';
    case Image = 'image';
    case Audio = 'audio';
    case Archive = 'archive';
    case Link = 'link';
    case Text = 'text';
    case Other = 'other';
}
