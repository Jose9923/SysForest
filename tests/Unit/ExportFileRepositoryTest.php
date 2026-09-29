<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ExportFileRepository;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Usa un directorio desechable propio; no visita el almacenamiento de la aplicación. */
class ExportFileRepositoryTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->workspace);
        }
        parent::tearDown();
    }

    public function test_cleanup_removes_only_old_owned_parts_and_preserves_other_files_and_symlinks(): void
    {
        $repository = $this->repository();
        $directory = $repository->directory();
        $path = $this->workspace;
        $cutoff = time() - 3600;
        $old = $repository->create();
        $recent = $repository->create();
        $boundary = $repository->create();
        $this->assertTrue(touch($old, $cutoff - 1));
        $this->assertTrue(touch($recent, $cutoff + 1));
        $this->assertTrue(touch($boundary, $cutoff));

        $unowned = $directory.'/documento-ajeno.part';
        $extension = $directory.'/00000000-0000-4000-8000-000000000001.csv';
        $outside = $path.'/evidencia-ajena.jpg';
        foreach ([$unowned, $extension, $outside] as $file) {
            $this->assertNotFalse(file_put_contents($file, 'contenido ajeno que debe conservarse'));
            $this->assertTrue(touch($file, $cutoff - 60));
        }
        $nestedDirectory = $directory.'/subdirectorio';
        $this->assertTrue(mkdir($nestedDirectory, 0700));
        $nested = $nestedDirectory.'/00000000-0000-4000-8000-000000000002.part';
        $this->assertNotFalse(file_put_contents($nested, 'archivo fuera de la profundidad permitida'));
        $this->assertTrue(touch($nested, $cutoff - 60));
        $symlink = $directory.'/00000000-0000-4000-8000-000000000003.part';
        $this->assertTrue(symlink($outside, $symlink));
        clearstatcache();

        $this->assertSame(1, $repository->cleanup($cutoff));
        $this->assertFileDoesNotExist($old);
        foreach ([$recent, $boundary, $unowned, $extension, $outside, $nested] as $preserved) {
            $this->assertFileExists($preserved);
        }
        $this->assertTrue(is_link($symlink));
        $this->assertSame($outside, readlink($symlink));
        $this->assertSame('contenido ajeno que debe conservarse', file_get_contents($outside));
        $this->assertSame(0, $repository->cleanup($cutoff));
    }

    public function test_cleanup_recovers_crashed_xlsx_workspaces_with_or_without_a_part_file(): void
    {
        $repository = $this->repository();
        $cutoff = time() - 3600;
        $part = $repository->create();
        $work = $this->xlsxWorkspace($part, $cutoff - 1);
        touch($part, $cutoff - 1);
        $orphan = $repository->create();
        unlink($orphan);
        $orphanWork = $this->xlsxWorkspace($orphan, $cutoff - 1);
        clearstatcache();

        $this->assertSame(2, $repository->cleanup($cutoff));
        $this->assertFileDoesNotExist($part);
        $this->assertDirectoryDoesNotExist($work);
        $this->assertDirectoryDoesNotExist($orphanWork);
        $this->assertSame(0, $repository->cleanup($cutoff));
    }

    public function test_cleanup_preserves_recent_boundary_and_unowned_workspaces(): void
    {
        $repository = $this->repository();
        $cutoff = time() - 3600;
        $activePart = $repository->create();
        touch($activePart, $cutoff - 60);
        $activeWork = $this->xlsxWorkspace($activePart, $cutoff + 1);
        $boundary = $repository->create();
        unlink($boundary);
        $boundaryWork = $this->xlsxWorkspace($boundary, $cutoff);
        $recent = $repository->create();
        unlink($recent);
        $recentWork = $this->xlsxWorkspace($recent, $cutoff + 1);
        $unownedWork = $this->xlsxWorkspace($repository->directory().'/ajeno.part', $cutoff - 60);
        $sibling = $repository->directory().'-ajeno';
        mkdir($sibling, 0700);
        $outsidePart = $sibling.'/00000000-0000-4000-8000-000000000009.part';
        file_put_contents($outsidePart, 'ajeno');
        $outsideWork = $this->xlsxWorkspace($outsidePart, $cutoff - 60);
        clearstatcache();

        $repository->remove($outsidePart);
        $this->assertSame(0, $repository->cleanup($cutoff));
        $this->assertFileExists($activePart);
        $this->assertFileExists($outsidePart);
        foreach ([$activeWork, $boundaryWork, $recentWork, $unownedWork, $outsideWork] as $preserved) {
            $this->assertFileExists($preserved.'/xlsx-crash/xl/worksheets/sheet1.xml');
        }
    }

    public function test_cleanup_never_follows_workspace_or_nested_symlinks(): void
    {
        $repository = $this->repository();
        $cutoff = time() - 3600;
        $external = $this->workspace.'/ajeno';
        mkdir($external, 0700);
        file_put_contents($external.'/preservar.xml', 'contenido privado ajeno');
        $linkedPart = $repository->create();
        unlink($linkedPart);
        $this->assertTrue(symlink($external, $linkedPart.'.work'));
        $nestedPart = $repository->create();
        unlink($nestedPart);
        $nestedWork = $this->xlsxWorkspace($nestedPart, $cutoff - 60);
        $this->assertTrue(symlink($external, $nestedWork.'/enlace'));
        touch($nestedWork, $cutoff - 60);
        clearstatcache();

        $repository->remove($linkedPart);
        $repository->remove($nestedPart);
        $this->assertSame(0, $repository->cleanup($cutoff));
        $this->assertTrue(is_link($linkedPart.'.work'));
        $this->assertTrue(is_link($nestedWork.'/enlace'));
        $this->assertFileExists($nestedWork.'/xlsx-crash/xl/worksheets/sheet1.xml');
        $this->assertSame('contenido privado ajeno', file_get_contents($external.'/preservar.xml'));
    }

    public function test_remove_purges_only_the_matching_workspace_even_when_the_part_is_already_absent(): void
    {
        $repository = $this->repository();
        $part = $repository->create();
        $work = $this->xlsxWorkspace($part, time());
        $otherPart = $repository->create();
        $otherWork = $this->xlsxWorkspace($otherPart, time());

        $repository->remove($part);
        $this->assertFileDoesNotExist($part);
        $this->assertDirectoryDoesNotExist($work);
        $this->assertFileExists($otherPart);
        $this->assertFileExists($otherWork.'/xlsx-crash/xl/worksheets/sheet1.xml');
        unlink($otherPart);
        $repository->remove($otherPart);
        $this->assertDirectoryDoesNotExist($otherWork);
    }

    private function xlsxWorkspace(string $part, int $modifiedAt): string
    {
        $work = $part.'.work';
        $this->assertTrue(mkdir($work.'/xlsx-crash/xl/worksheets', 0700, true));
        $this->assertNotFalse(file_put_contents($work.'/xlsx-crash/xl/worksheets/sheet1.xml', '<row>Dato privado</row>'));
        $this->assertTrue(touch($work, $modifiedAt));

        return $work;
    }

    private function repository(): ExportFileRepository
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-export-cleanup-');
        $this->assertNotFalse($path);
        unlink($path);
        $this->workspace = $path;
        $this->assertTrue(mkdir($path, 0700));
        $directory = $path.'/exports';
        $this->assertTrue(mkdir($directory, 0700));

        return new class ($directory) extends ExportFileRepository {
            public function __construct(private string $temporaryDirectory)
            {
            }

            public function directory(): string
            {
                return $this->temporaryDirectory;
            }
        };
    }
}
