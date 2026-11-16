<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\ImageProcessor;
use App\Runtime\SafeFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Theme images ship as WebP already (tools/images/convert-theme-images.sh); this command is for the JPEG/PNG/GIF
 * files that remain (uploads, kept originals).
 * Writes a WebP copy (quality 85) next to every JPEG/PNG/GIF under public/ (`foo.png` → `foo.png.webp`).
 * Animated images and files where WebP is not smaller are skipped. public/.htaccess serves the copy to
 * browsers that accept WebP, so templates and CSS keep their original URLs. The installer runs it once;
 * run it again after adding images (deploy step). Up-to-date copies are skipped, --force rebuilds,
 * --clean removes all copies.
 */
#[AsCommand(name: 'app:images:webp', description: 'Creates WebP copies (Q85) of the static game images')]
final class ImagesWebpCommand extends Command
{
    public function __construct(
        private readonly ImageProcessor $images,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'folder below public/ (default: all of public/)', '')
            ->addOption('force', null, InputOption::VALUE_NONE, 'rebuild existing copies')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'remove all .webp copies instead');
    }

    /** Converts (or with --clean removes) the WebP copies under public/[path]; prints the saved size. Exit code FAILURE for an unknown folder. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = rtrim($this->publicDir . '/' . trim((string) $input->getArgument('path'), '/'), '/');
        if (!is_dir($root)) {
            $io->error('No such folder: ' . $root);

            return Command::FAILURE;
        }

        if ($input->getOption('clean')) {
            $removed = 0;
            $copies = new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)),
                '/\.(png|jpe?g|gif)\.webp$/i',
            );
            foreach ($copies as $copy) {
                $removed += (int) SafeFile::remove($copy->getPathname());
            }
            $io->success(sprintf('%d WebP copies removed.', $removed));

            return Command::SUCCESS;
        }

        $stats = $this->images->webpTree($root, (bool) $input->getOption('force'));
        $io->success(sprintf(
            '%d images with a WebP copy (%s → %s), %d skipped (animated, not smaller or unsupported).',
            $stats['converted'],
            $this->size($stats['before']),
            $this->size($stats['after']),
            $stats['skipped'],
        ));

        return Command::SUCCESS;
    }

    /** Human-readable size: KB, or MB with one decimal above 1 MiB. */
    private function size(int $bytes): string
    {
        return $bytes > 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
