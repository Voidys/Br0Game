<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\ImageProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Width variants (`foo.webp` → `foo-480w.webp`, `foo-720w.webp`, only widths below the original) of the WebP images
 * below a folder, for `srcset`. Used for the start page memes (public/memes: widths 480,720), the login screenshots
 * (320,480,720) and the news uploads (480,800,1200); the installer-free way to backfill after dropping a new file in:
 * `bin/console app:images:variants memes --widths=480,720`. Up-to-date variants are skipped, --force rebuilds,
 * --clean removes all variants. Variants are never treated as images of their own by the controllers.
 */
#[AsCommand(name: 'app:images:variants', description: 'Creates srcset width variants (<name>-<w>w.webp) of WebP images')]
final class ImagesVariantsCommand extends Command
{
    public function __construct(
        private readonly ImageProcessor $images,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
        parent::__construct();
    }

    /** Arguments and options: path (required), --widths, --force, --clean. */
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'folder below public/ (e.g. memes, uploads/news, styles/resource/images/login/screens)')
            ->addOption('widths', null, InputOption::VALUE_REQUIRED, 'comma separated pixel widths', '480,720')
            ->addOption('force', null, InputOption::VALUE_NONE, 'rebuild existing variants')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'remove all variants instead');
    }

    /** Writes (or with --clean removes) the variants below public/<path>; FAILURE for an unknown folder or no valid width. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = rtrim($this->publicDir . '/' . trim((string) $input->getArgument('path'), '/'), '/');
        $widths = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('widths')))));
        if (!is_dir($root) || $widths === []) {
            $io->error('No such folder or no widths: ' . $root);

            return Command::FAILURE;
        }

        if ($input->getOption('clean')) {
            $removed = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (ImageProcessor::isVariant($file->getPathname()) && \App\Runtime\SafeFile::remove($file->getPathname())) {
                    $removed++;
                }
            }
            $io->success(sprintf('%d variants removed.', $removed));

            return Command::SUCCESS;
        }

        $stats = $this->images->variantTree($root, $widths, (bool) $input->getOption('force'));
        $io->success(sprintf('%d images checked, %d variants written (%d KB of variants in total).', $stats['files'], $stats['variants'], intdiv($stats['bytes'], 1024)));

        return Command::SUCCESS;
    }
}
