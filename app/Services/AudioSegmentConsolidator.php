<?php

namespace App\Services;

use App\Models\Consultation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class AudioSegmentConsolidator
{
    public function maxBytes(): int
    {
        return max(1, (int) config('services.openai.single_transcription_max_kb', 24576)) * 1024;
    }

    public function consolidate(Consultation $consultation): string
    {
        $segments = $consultation->audioSegments()->orderBy('segment_number')->get();
        if ($segments->isEmpty()) {
            throw new RuntimeException('No hay fragmentos de audio para consolidar.');
        }

        foreach ($segments as $segment) {
            if (! Storage::disk('local')->exists($segment->storage_path)) {
                throw new RuntimeException('Falta un fragmento requerido para consolidar el audio.');
            }
        }

        if ($segments->count() === 1) {
            return $segments->first()->storage_path;
        }

        $directory = 'consultations/'.$consultation->session_uuid.'/consolidated';
        Storage::disk('local')->makeDirectory($directory);
        $outputPath = $directory.'/consultation.m4a';
        $output = Storage::disk('local')->path($outputPath);

        if (Storage::disk('local')->exists($outputPath) && filesize($output) > 0 && filesize($output) <= $this->maxBytes()) {
            return $outputPath;
        }

        Storage::disk('local')->delete($outputPath);
        $listPath = $directory.'/segments.txt';
        $list = Storage::disk('local')->path($listPath);
        $contents = $segments->map(function ($segment): string {
            $path = Storage::disk('local')->path($segment->storage_path);

            return "file '".str_replace("'", "'\\''", $path)."'";
        })->implode(PHP_EOL);
        file_put_contents($list, $contents.PHP_EOL, LOCK_EX);

        try {
            $this->run([
                $this->binary(), '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'concat', '-safe', '0', '-i', $list,
                '-c', 'copy', '-movflags', '+faststart', $output,
            ]);
        } catch (Throwable $copyFailure) {
            Storage::disk('local')->delete($outputPath);
            try {
                $this->run([
                    $this->binary(), '-hide_banner', '-loglevel', 'error', '-y',
                    '-f', 'concat', '-safe', '0', '-i', $list,
                    '-vn', '-c:a', 'aac', '-b:a', '48k', '-ar', '16000', '-ac', '1',
                    '-movflags', '+faststart', $output,
                ]);
            } catch (Throwable $encodeFailure) {
                throw new RuntimeException('No se pudieron consolidar los fragmentos de audio.', previous: $encodeFailure);
            }
        } finally {
            Storage::disk('local')->delete($listPath);
        }

        clearstatcache(true, $output);
        $size = is_file($output) ? filesize($output) : false;
        if ($size === false || $size <= 0) {
            Storage::disk('local')->delete($outputPath);
            throw new RuntimeException('El audio consolidado quedó vacío.');
        }
        if ($size > $this->maxBytes()) {
            Storage::disk('local')->delete($outputPath);
            throw new RuntimeException('El audio consolidado supera el límite para transcripción única.');
        }

        return $outputPath;
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(max(1, (int) config('services.openai.ffmpeg_timeout', 45)));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function binary(): string
    {
        return (string) config('services.openai.ffmpeg_binary', 'ffmpeg');
    }
}
