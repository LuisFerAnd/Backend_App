<?php

namespace App\Services;

class TranscriptMerger
{
    public function merge(array $transcripts): string
    {
        $merged = '';

        foreach ($transcripts as $transcript) {
            $next = trim((string) $transcript);
            if ($next === '') {
                continue;
            }
            if ($merged === '') {
                $merged = $next;

                continue;
            }

            $mergedWords = preg_split('/\s+/u', $merged) ?: [];
            $nextWords = preg_split('/\s+/u', $next) ?: [];
            $maxOverlap = min(20, count($mergedWords), count($nextWords));
            $overlap = 0;

            for ($length = $maxOverlap; $length > 0; $length--) {
                $tail = array_slice($mergedWords, -$length);
                $head = array_slice($nextWords, 0, $length);
                if ($this->normalize($tail) === $this->normalize($head)) {
                    $overlap = $length;
                    break;
                }
            }

            $remaining = implode(' ', array_slice($nextWords, $overlap));
            if ($remaining !== '') {
                $merged .= "\n\n".$remaining;
            }
        }

        return trim($merged);
    }

    private function normalize(array $words): string
    {
        return implode(' ', array_map(
            static fn (string $word): string => mb_strtolower(
                trim($word, " \t\n\r\0\x0B.,;:!?¿¡()[]{}\"'"),
                'UTF-8'
            ),
            $words
        ));
    }
}
