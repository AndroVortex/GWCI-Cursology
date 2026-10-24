<?php

declare(strict_types=1);

namespace App\Models\Enums;

/**
 * course_chapter_test_question.selection_mode (ck_question_mode) y
 * test_attempt_question.selection_mode_snapshot (ck_attempt_question_mode).
 */
enum SelectionMode: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
