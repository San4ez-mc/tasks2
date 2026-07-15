<?php

namespace App\Services;

class TelegramMessageClassifierService
{
    public function isGoalListRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        $hasGoalWord = str_contains($normalized, 'ціль')
            || str_contains($normalized, 'цілі')
            || str_contains($normalized, 'цілей')
            || str_contains($normalized, 'тілей')
            || str_contains($normalized, 'тiлей');
        if (!$hasGoalWord) {
            return false;
        }

        return $this->containsAnyMarker($normalized, ['виведи', 'покажи', 'показати', 'список', 'які', 'мої', 'моїх', 'скажи', 'відобрази']);
    }

    public function isTaskListRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->containsAnyMarker($normalized, ['створи', 'створити', 'додай', 'додати', 'постав', 'поставити', 'заведи', 'зроби', 'видали', 'видалити', 'прибери', 'перенеси', 'зміни', 'онови', 'відредагуй'])) {
            return false;
        }

        $hasTaskWord = str_contains($normalized, 'задач') || str_contains($normalized, 'задачі') || str_contains($normalized, 'задачу') || str_contains($normalized, 'таск');
        if (!$hasTaskWord) {
            return false;
        }

        return $this->containsAnyMarker($normalized, ['виведи', 'покажи', 'показати', 'список', 'які', 'дай', 'скажи', 'відобрази', 'що в мене']);
    }

    public function isDeleteRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        return $this->containsAnyMarker($normalized, ['видали', 'видалити', 'прибери', 'стерти', 'очисти']);
    }

    public function detectDeleteEntityType(string $text): string
    {
        $normalized = $this->normalize($text);
        if (str_contains($normalized, 'ціль') || str_contains($normalized, 'цілі') || str_contains($normalized, 'ціл')) {
            return 'goal';
        }
        return 'task';
    }

    public function isMarkDoneRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        $hasDoneMarker = $this->containsAnyMarker($normalized, ['виконано', 'виконай', 'зроблено', 'заверши', 'завершити', 'закрий', 'закрити', 'познач виконан', 'познач як виконан', 'познач готов']);
        if (!$hasDoneMarker) {
            return false;
        }

        $hasEntityWord = str_contains($normalized, 'задач')
            || str_contains($normalized, 'таск')
            || str_contains($normalized, 'ціль')
            || str_contains($normalized, 'цілі')
            || str_contains($normalized, 'ціл');

        return $hasEntityWord;
    }

    public function detectMarkDoneEntityType(string $text): string
    {
        $normalized = $this->normalize($text);
        if (str_contains($normalized, 'ціль') || str_contains($normalized, 'цілі') || str_contains($normalized, 'ціл')) {
            return 'goal';
        }
        return 'task';
    }

    private function containsAnyMarker(string $normalizedText, array $markers): bool
    {
        foreach ($markers as $marker) {
            if ($this->containsMarker($normalizedText, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function isProjectListRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->containsAnyMarker($normalized, ['створи', 'створити', 'додай', 'додати', 'заведи', 'зроби', 'новий'])) {
            return false;
        }

        $hasProjectWord = str_contains($normalized, 'проект') || str_contains($normalized, 'project');
        if (!$hasProjectWord) {
            return false;
        }

        return $this->containsAnyMarker($normalized, ['покажи', 'показати', 'список', 'які', 'дай', 'виведи', 'відобрази', 'мої', 'скажи']);
    }

    public function isProjectCreateRequest(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }

        $hasProjectWord = str_contains($normalized, 'проект') || str_contains($normalized, 'project');
        if (!$hasProjectWord) {
            return false;
        }

        return $this->containsAnyMarker($normalized, ['створи', 'створити', 'додай', 'додати', 'заведи', 'зроби', 'новий']);
    }

    private function containsMarker(string $normalizedText, string $marker): bool
    {
        $marker = trim(mb_strtolower($marker));
        if ($marker === '') {
            return false;
        }

        if (str_contains($marker, ' ')) {
            return mb_strpos($normalizedText, $marker) !== false;
        }

        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}_])/u';
        return preg_match($pattern, $normalizedText) === 1;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}