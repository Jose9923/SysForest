<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Str;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Temporales privados por petición; nunca acepta rutas procedentes del cliente. */
class ExportFileRepository
{
    public function directory(): string
    {
        $directory = storage_path('app/private/report-downloads');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de reportes.');
        }

        return $directory;
    }

    public function create(): string
    {
        $path = $this->directory().'/'.Str::uuid().'.part';
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('No fue posible preparar el archivo temporal.');
        }
        fclose($handle);
        chmod($path, 0600);

        return $path;
    }

    public function remove(string $path): void
    {
        if (!$this->owned($path) || is_link($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
        $this->removeWorkspace($path.'.work');
    }

    /** Recupera únicamente huérfanos antiguos propios; no recorre almacenamiento de evidencias. */
    public function cleanup(int $olderThan): int
    {
        $count = 0;
        foreach (glob($this->directory().'/*.part') ?: [] as $path) {
            $work = $path.'.work';
            if ($this->owned($path) && is_file($path) && !is_link($path) && filemtime($path) < $olderThan
                && !(is_dir($work) && !is_link($work) && filemtime($work) >= $olderThan)) {
                $this->remove($path);
                $count++;
            }
        }
        // Una interrupción puede dejar XML de OpenSpout aunque el .part ya no exista.
        foreach (glob($this->directory().'/*.part.work') ?: [] as $work) {
            $part = substr($work, 0, -5);
            if (!is_link($part) && !is_file($part) && $this->ownedWorkspace($work)
                && filemtime($work) < $olderThan && $this->removeWorkspace($work)) {
                $count++;
            }
        }

        return $count;
    }

    private function owned(string $path): bool
    {
        $directory = $this->directory();

        return realpath($directory) === $directory && dirname($path) === $directory
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.part\z/D', basename($path)) === 1;
    }

    private function ownedWorkspace(string $path): bool
    {
        return str_ends_with($path, '.part.work') && $this->owned(substr($path, 0, -5))
            && is_dir($path) && !is_link($path) && realpath($path) === $path
            && realpath($this->directory()) === dirname($path);
    }

    /** Revisa todo el árbol antes de borrar: un enlace o entrada especial conserva el workspace completo. */
    private function removeWorkspace(string $path): bool
    {
        if (!$this->ownedWorkspace($path)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        $entries = [];
        foreach ($files as $file) {
            $entry = $file->getPathname();
            $resolved = realpath($entry);
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())
                || $resolved === false || !str_starts_with($resolved, $path.DIRECTORY_SEPARATOR)) {
                return false;
            }
            $entries[] = [$entry, $file->isDir()];
        }
        foreach ($entries as [$entry, $directory]) {
            // Recomprueba el límite por si una entrada cambió desde el recorrido anterior.
            $resolved = realpath($entry);
            if (is_link($entry) || $resolved === false || !str_starts_with($resolved, $path.DIRECTORY_SEPARATOR)) {
                return false;
            }
            $directory ? rmdir($entry) : unlink($entry);
        }

        return rmdir($path);
    }
}
